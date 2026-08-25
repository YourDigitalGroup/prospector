<?php

declare(strict_types=1);

namespace Prospector\Http;

use Prospector\Auth;
use Prospector\Automations;
use Prospector\GoHighLevel;
use Prospector\Leads;
use Prospector\Support\Request;
use Prospector\Support\View;
use Prospector\Users;

/**
 * The GoHighLevel workspace: the screens Billy and Darren actually sell from.
 *
 * Everything here reads and writes a live GoHighLevel sub-account. Nothing is
 * mirrored into Prospector's own database — a stale copy of a CRM is worse than
 * no copy, and the daily-brief pipeline already owns the lead records.
 *
 * Whose sub-account is in play: each user connects their own token, and an
 * admin can look at anyone's with ?user_id=. Non-admins only ever get their own,
 * which is enforced in resolveUser() rather than in each action.
 */
final class Workspace
{
    /** Panels that can fail independently without taking the screen down. */
    private const TABS = [
        'board' => 'Pipeline',
        'contacts' => 'Contacts',
        'inbox' => 'Inbox',
        'automations' => 'Automations',
    ];

    // ------------------------------------------------------------ screens

    public static function board(): void
    {
        [$user, $client] = self::context();

        if ($client === null) {
            self::promptToConnect($user);
        }

        $pipelines = $client->pipelines();

        if (!$pipelines['ok']) {
            self::render('workspace/board', $user, [
                'title' => 'Pipeline',
                'tab' => 'board',
                'error' => $pipelines['error'],
                'pipelines' => [],
                'pipeline' => null,
                'stages' => [],
                'cards' => [],
                'atLimit' => false,
            ]);

            return;
        }

        $chosenId = Request::input('pipeline');
        if ($chosenId === '') {
            $chosenId = (string) ($user['ghl_pipeline_id'] ?? '');
        }

        $pipeline = self::pickPipeline($pipelines['pipelines'], $chosenId);

        // Remember the choice so the board opens on the same one next time.
        if ($pipeline !== null && (string) ($user['ghl_pipeline_id'] ?? '') !== (string) $pipeline['id']) {
            Users::update((int) $user['id'], ['ghl_pipeline_id' => (string) $pipeline['id']]);
        }

        $stages = [];
        $cards = [];
        $error = null;
        $atLimit = false;

        if ($pipeline === null) {
            $error = 'This sub-account has no pipelines yet. Create one in GoHighLevel first.';
        } else {
            foreach (($pipeline['stages'] ?? []) as $stage) {
                if (!is_array($stage) || !isset($stage['id'])) {
                    continue;
                }
                $stages[] = ['id' => (string) $stage['id'], 'name' => (string) ($stage['name'] ?? 'Untitled')];
                $cards[(string) $stage['id']] = [];
            }

            $result = $client->opportunitiesForPipeline((string) $pipeline['id']);

            if (!$result['ok']) {
                $error = $result['error'];
            } else {
                $atLimit = count($result['opportunities']) >= 100;

                foreach ($result['opportunities'] as $opportunity) {
                    $stageId = (string) ($opportunity['pipelineStageId'] ?? '');
                    if (!isset($cards[$stageId])) {
                        // A stage that has been deleted out from under an open
                        // deal — show it rather than silently dropping the card.
                        $stages[] = ['id' => $stageId, 'name' => 'Unknown stage'];
                        $cards[$stageId] = [];
                    }
                    $cards[$stageId][] = $opportunity;
                }
            }
        }

        self::render('workspace/board', $user, [
            'title' => 'Pipeline',
            'tab' => 'board',
            'error' => $error,
            'pipelines' => $pipelines['pipelines'],
            'pipeline' => $pipeline,
            'stages' => $stages,
            'cards' => $cards,
            'atLimit' => $atLimit,
        ]);
    }

    public static function contacts(): void
    {
        [$user, $client] = self::context();

        if ($client === null) {
            self::promptToConnect($user);
        }

        $query = Request::input('q');
        $result = $client->contacts(50, $query);

        self::render('workspace/contacts', $user, [
            'title' => 'Contacts',
            'tab' => 'contacts',
            'contacts' => $result['contacts'],
            'error' => $result['ok'] ? null : $result['error'],
            'query' => $query,
        ]);
    }

    public static function contact(): void
    {
        [$user, $client] = self::context();

        if ($client === null) {
            self::promptToConnect($user);
        }

        $contactId = Request::input('id');
        if ($contactId === '') {
            Controller::notFound('No contact was specified.');
        }

        $result = $client->contact($contactId);

        if (!$result['ok']) {
            Controller::flash('error', $result['error']);
            Controller::redirect(self::link('/ghl/contacts', $user));
        }

        // Each panel is fetched separately and allowed to fail on its own: a
        // missing conversations scope should cost the inbox panel, not the page.
        $notes = $client->notes($contactId);
        $tasks = $client->tasks($contactId);
        $conversations = $client->conversations($contactId, 10);
        $workflows = $client->workflows();

        $messages = ['ok' => true, 'messages' => [], 'error' => ''];
        $conversationId = '';

        if ($conversations['ok'] && $conversations['conversations'] !== []) {
            $conversationId = (string) ($conversations['conversations'][0]['id'] ?? '');
            if ($conversationId !== '') {
                $messages = $client->messages($conversationId);
            }
        }

        self::render('workspace/contact', $user, [
            'title' => trim((string) ($result['contact']['contactName'] ?? $result['contact']['firstName'] ?? 'Contact')),
            'tab' => 'contacts',
            'contact' => $result['contact'],
            'contactId' => $contactId,
            'notes' => $notes,
            'tasks' => $tasks,
            'conversations' => $conversations,
            'messages' => $messages,
            'conversationId' => $conversationId,
            'workflows' => $workflows,
        ]);
    }

    public static function inbox(): void
    {
        [$user, $client] = self::context();

        if ($client === null) {
            self::promptToConnect($user);
        }

        $result = $client->conversations('', 100);
        $channel = strtolower(Request::input('channel'));
        $unreadOnly = Request::input('unread') === '1';

        $conversations = $result['conversations'];

        // Filtered here rather than in the API call: GoHighLevel's conversation
        // search does not take a channel, and a client-side filter over 100 rows
        // is cheaper than being wrong about what it supports.
        $conversations = array_values(array_filter(
            $conversations,
            static function (array $c) use ($channel, $unreadOnly): bool {
                if ($unreadOnly && (int) ($c['unreadCount'] ?? 0) === 0) {
                    return false;
                }

                if ($channel === '' || $channel === 'all') {
                    return true;
                }

                $type = strtolower((string) ($c['type'] ?? $c['lastMessageType'] ?? ''));

                return $channel === 'email'
                    ? str_contains($type, 'email')
                    : !str_contains($type, 'email');
            }
        ));

        // Match conversations back to the leads they belong to, so the inbox
        // links somewhere useful instead of being a dead end.
        $byContact = [];
        foreach ($conversations as $conversation) {
            $contactId = (string) ($conversation['contactId'] ?? '');
            if ($contactId !== '') {
                $byContact[$contactId] = true;
            }
        }
        $leadsByContact = Leads::byGhlContactIds(array_keys($byContact));

        self::render('workspace/inbox', $user, [
            'title' => 'Inbox',
            'tab' => 'inbox',
            'conversations' => $conversations,
            'total' => count($result['conversations']),
            'channel' => $channel,
            'unreadOnly' => $unreadOnly,
            'leadsByContact' => $leadsByContact,
            'error' => $result['ok'] ? null : $result['error'],
        ]);
    }

    public static function automations(): void
    {
        [$user, $client] = self::context();

        if ($client === null) {
            self::promptToConnect($user);
        }

        $workflows = $client->workflows();
        $agents = $client->aiAgents();

        self::render('workspace/automations', $user, [
            'title' => 'Automations',
            'tab' => 'automations',
            'workflows' => $workflows,
            'agents' => $agents,
            'rules' => Automations::rules((int) $user['id']),
            'events' => Automations::EVENTS,
            'statuses' => Leads::STATUSES,
        ]);
    }

    // --------------------------------------------------------- connecting

    public static function connect(): void
    {
        Controller::requireLogin();

        $user = self::resolveUser();
        $client = GoHighLevel::forUser($user);

        $connection = null;
        if ($client !== null) {
            $connection = $client->testConnection();
        }

        self::render('workspace/connect', $user, [
            'title' => 'Connect GoHighLevel',
            'tab' => '',
            'connection' => $connection,
            'hasToken' => Users::ghlToken($user) !== '',
            'locationId' => (string) ($user['ghl_location_id'] ?? ''),
        ], false);
    }

    public static function connectSave(): void
    {
        Controller::requireLogin();
        self::csrf();

        $user = self::resolveUser();
        $location = Request::input('ghl_location_id');
        $token = Request::raw('ghl_token');

        $update = ['ghl_location_id' => $location !== '' ? $location : null];

        // An empty token field means "leave it alone", so a location edit does
        // not silently wipe the credential.
        if (trim($token) !== '') {
            $update['ghl_token'] = trim($token);
        }

        Users::update((int) $user['id'], $update);

        $fresh = Users::find((int) $user['id']);
        $client = $fresh !== null ? GoHighLevel::forUser($fresh) : null;

        if ($client === null) {
            Controller::flash('error', 'Both a private integration token and a Location ID are needed.');
            Controller::redirect(self::link('/ghl/connect', $user));
        }

        $test = $client->testConnection();
        Controller::flash($test['ok'] ? 'success' : 'error', $test['message']);

        Controller::redirect(self::link($test['ok'] ? '/ghl' : '/ghl/connect', $user));
    }

    public static function disconnect(): void
    {
        Controller::requireLogin();
        self::csrf();

        $user = self::resolveUser();
        Users::update((int) $user['id'], [
            'ghl_token' => '',
            'ghl_location_id' => null,
            'ghl_pipeline_id' => null,
        ]);

        Controller::flash('success', 'Disconnected from GoHighLevel.');
        Controller::redirect(self::link('/ghl/connect', $user));
    }

    // ------------------------------------------------------------ actions

    public static function move(): void
    {
        [$user, $client] = self::context(true);
        self::csrf();

        $result = $client->moveOpportunity(
            Request::input('opportunity_id'),
            Request::input('pipeline_id'),
            Request::input('stage_id')
        );

        // The board moves the card optimistically, so a failure has to come
        // back as JSON for the page to put it back where it was.
        if (Request::wantsJson()) {
            self::json($result);
        }

        Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Controller::redirect(self::link('/ghl', $user));
    }

    public static function status(): void
    {
        [$user, $client] = self::context(true);
        self::csrf();

        $result = $client->setOpportunityStatus(Request::input('opportunity_id'), Request::input('status'));

        Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Controller::redirect(self::link('/ghl', $user));
    }

    public static function note(): void
    {
        [$user, $client] = self::context(true);
        self::csrf();

        $contactId = Request::input('contact_id');
        $body = trim(Request::raw('body'));

        if ($body === '') {
            Controller::flash('error', 'The note was empty.');
        } else {
            $result = $client->addNote($contactId, $body);
            Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
        }

        Controller::redirect(self::link('/ghl/contact', $user, ['id' => $contactId]));
    }

    public static function task(): void
    {
        [$user, $client] = self::context(true);
        self::csrf();

        $contactId = Request::input('contact_id');

        if (Request::input('task_id') !== '') {
            $result = $client->completeTask($contactId, Request::input('task_id'));
        } else {
            $title = trim(Request::input('title'));
            $result = $title === ''
                ? ['ok' => false, 'message' => 'A task needs a title.']
                : $client->addTask($contactId, $title, Request::input('due_date'));
        }

        Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Controller::redirect(self::link('/ghl/contact', $user, ['id' => $contactId]));
    }

    /**
     * Sending reaches a real prospect, so this deliberately has no "quick send"
     * path: the form posts a confirm flag that the UI only sets after the rep
     * has seen exactly what is going out.
     */
    public static function send(): void
    {
        [$user, $client] = self::context(true);
        self::csrf();

        $contactId = Request::input('contact_id');
        $type = strtoupper(Request::input('type')) === 'EMAIL' ? 'Email' : 'SMS';
        $body = trim(Request::raw('body'));

        if (Request::input('confirm') !== '1') {
            Controller::flash('error', 'Nothing was sent — the confirmation step was not completed.');
            Controller::redirect(self::link('/ghl/contact', $user, ['id' => $contactId]));
        }

        $result = $client->sendMessage($contactId, $type, $body, Request::input('subject'));

        Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Controller::redirect(self::link('/ghl/contact', $user, ['id' => $contactId]));
    }

    /**
     * Create, pause, resume or delete an automatic enrolment rule.
     *
     * Rules belong to the owner whose leads they act on — a workflow lives in a
     * sub-account, and Billy's automations do not exist in Darren's.
     */
    public static function rule(): void
    {
        [$user] = self::context(true);
        self::csrf();

        $action = Request::input('action');
        $back = self::link('/ghl/automations', $user);

        if ($action === 'add') {
            $created = Automations::addRule(
                (int) $user['id'],
                Request::input('workflow_id'),
                Request::input('workflow_name'),
                Request::input('on_event'),
                Request::input('event_value')
            );

            Controller::flash(
                $created > 0 ? 'success' : 'error',
                $created > 0
                    ? 'Rule saved. It runs from now on, and the next scheduled sweep catches up any '
                        . 'leads already on file that match.'
                    : 'That rule needs an automation and a trigger.'
            );

            Controller::redirect($back);
        }

        $rule = Automations::rule(Request::int('rule_id'));

        if ($rule === null || !Auth::canAccessUser((int) $rule['user_id'])) {
            Controller::forbidden();
        }

        match ($action) {
            'pause' => Automations::setRuleActive((int) $rule['id'], false),
            'resume' => Automations::setRuleActive((int) $rule['id'], true),
            'delete' => Automations::deleteRule((int) $rule['id']),
            default => null,
        };

        Controller::flash('success', match ($action) {
            'pause' => 'Rule paused. Nobody new gets added until you resume it.',
            'resume' => 'Rule running again.',
            'delete' => 'Rule deleted. Anyone already enrolled stays enrolled.',
            default => 'Nothing changed.',
        });

        Controller::redirect($back);
    }

    /**
     * Run the state-based rules on demand rather than waiting for the scheduler.
     */
    public static function sweep(): void
    {
        [$user] = self::context(true);
        self::csrf();

        $result = Automations::sweep();

        Controller::flash(
            $result['failed'] > 0 ? 'error' : 'success',
            $result['enrolled'] . ' ' . ($result['enrolled'] === 1 ? 'lead' : 'leads') . ' enrolled'
            . ($result['failed'] > 0 ? ', ' . $result['failed'] . ' failed' : '')
            . ($result['enrolled'] === 0 && $result['failed'] === 0 ? ' — everyone matching is already in' : '')
            . '.'
        );

        Controller::redirect(self::link('/ghl/automations', $user));
    }

    public static function enroll(): void
    {
        [$user, $client] = self::context(true);
        self::csrf();

        $contactId = Request::input('contact_id');
        $workflowId = Request::input('workflow_id');

        $result = $workflowId === ''
            ? ['ok' => false, 'message' => 'Pick an automation first.']
            : $client->enrollInWorkflow($contactId, $workflowId);

        Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
        Controller::redirect(self::link('/ghl/contact', $user, ['id' => $contactId]));
    }

    // ------------------------------------------------------------ plumbing

    /**
     * Resolve the user whose sub-account is in play, and their client.
     *
     * @return array{0: array<string, mixed>, 1: GoHighLevel|null}
     */
    private static function context(bool $requireClient = false): array
    {
        Controller::requireLogin();

        $user = self::resolveUser();
        $client = GoHighLevel::forUser($user);

        if ($client === null && $requireClient) {
            Controller::flash('error', 'GoHighLevel is not connected.');
            Controller::redirect(self::link('/ghl/connect', $user));
        }

        return [$user, $client];
    }

    /**
     * An admin may act on another user's sub-account with ?user_id=; everyone
     * else is pinned to their own account regardless of what they send.
     *
     * @return array<string, mixed>
     */
    private static function resolveUser(): array
    {
        $self = Auth::user();

        if ($self === null) {
            Controller::redirect('/login');
        }

        if (!Auth::isAdmin()) {
            return $self;
        }

        $requested = Request::int('user_id', 0);
        if ($requested <= 0) {
            return $self;
        }

        $other = Users::find($requested);

        return $other ?? $self;
    }

    private static function promptToConnect(array $user): never
    {
        Controller::redirect(self::link('/ghl/connect', $user));
    }

    /**
     * Keep ?user_id= on every link so an admin browsing someone else's
     * sub-account does not silently snap back to their own mid-flow.
     *
     * @param array<string, mixed>       $user
     * @param array<string, string|int>  $query
     */
    private static function link(string $path, array $user, array $query = []): string
    {
        if (Auth::isAdmin() && (int) $user['id'] !== Auth::id()) {
            $query['user_id'] = (int) $user['id'];
        }

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     */
    private static function render(string $template, array $user, array $data, bool $withTabs = true): void
    {
        $data['tabs'] = $withTabs ? self::TABS : [];
        $data['workspaceUser'] = $user;
        $data['viewingOther'] = Auth::isAdmin() && (int) $user['id'] !== Auth::id();
        $data['otherUsers'] = Auth::isAdmin() ? Users::all(true) : [];

        View::page($template, $data);
    }

    /**
     * @param list<array<string, mixed>> $pipelines
     * @return array<string, mixed>|null
     */
    private static function pickPipeline(array $pipelines, string $wanted): ?array
    {
        if ($pipelines === []) {
            return null;
        }

        if ($wanted !== '') {
            foreach ($pipelines as $pipeline) {
                if ((string) ($pipeline['id'] ?? '') === $wanted) {
                    return $pipeline;
                }
            }
        }

        return $pipelines[0];
    }

    private static function csrf(): void
    {
        if (!Auth::verifyCsrf(Request::raw('csrf'))) {
            if (Request::wantsJson()) {
                http_response_code(419);
                self::json(['ok' => false, 'message' => 'Session expired — reload the page.']);
            }

            http_response_code(419);
            View::render('error_standalone', [
                'code' => 419,
                'heading' => 'Session expired',
                'message' => 'Reload the page and try that again.',
            ]);
            exit;
        }
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
