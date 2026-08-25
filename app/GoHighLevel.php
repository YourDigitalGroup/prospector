<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Settings;

/**
 * GoHighLevel API v2 client.
 *
 * Auth is a Private Integration token — each user connects their own sub-account
 * under Workspace → Connect, and an account-wide pair in Settings is the
 * fallback. Create one in GHL under Settings → Private Integrations.
 *
 * Scopes, and what stops working without each:
 *
 *   locations.readonly              the connection test
 *   contacts.readonly/.write        contacts, notes, tasks, pushing leads
 *   opportunities.readonly/.write   the pipeline board and moving cards
 *   conversations.readonly/.write   the inbox
 *   conversations/message.readonly  reading a thread
 *   conversations/message.write     sending email and SMS
 *   workflows.readonly              listing automations
 *
 * Conversation AI agents need the Conversation AI scopes, which some plans do
 * not include; that panel degrades to a notice rather than an error.
 */
final class GoHighLevel
{
    private const BASE = 'https://services.leadconnectorhq.com';
    private const VERSION = '2021-07-28';

    /**
     * The API host, overridable only by an environment variable so the suite can
     * point at a local stand-in. Deliberately not a UI setting: nothing a person
     * clicks should be able to redirect live credentials at another host.
     */
    private static function base(): string
    {
        $override = getenv('PROSPECTOR_GHL_BASE');

        return is_string($override) && $override !== '' ? rtrim($override, '/') : self::BASE;
    }

    public function __construct(
        private string $token,
        private string $locationId,
    ) {
    }

    /**
     * Resolve credentials for a user: their own if set, otherwise the
     * account-wide pair from Settings.
     *
     * @param array<string, mixed>|null $user
     */
    public static function forUser(?array $user = null): ?self
    {
        $token = '';
        $location = '';

        if ($user !== null) {
            $token = Users::ghlToken($user);
            $location = (string) ($user['ghl_location_id'] ?? '');
        }

        if ($token === '') {
            $token = Settings::get('ghl_token');
            if ($location === '') {
                $location = Settings::get('ghl_location_id');
            }
        }

        if ($token === '' || $location === '') {
            return null;
        }

        return new self($token, $location);
    }

    public static function isConfigured(): bool
    {
        return Settings::get('ghl_token') !== '' && Settings::get('ghl_location_id') !== '';
    }

    public function locationId(): string
    {
        return $this->locationId;
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $response = $this->request('GET', '/locations/' . rawurlencode($this->locationId));

        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['error']];
        }

        $name = $response['data']['location']['name'] ?? $response['data']['name'] ?? null;

        return [
            'ok' => true,
            'message' => 'Connected to ' . (is_string($name) ? $name : 'location ' . $this->locationId),
        ];
    }

    /**
     * Push a lead into GoHighLevel as a contact, then attach the qualification
     * detail as a note so the rep sees the same context the brief gave them.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, contact_id?: string, message: string}
     */
    public function pushLead(array $lead): array
    {
        $names = self::splitName((string) ($lead['decision_maker'] ?? ''));

        $payload = array_filter([
            'locationId' => $this->locationId,
            'firstName' => $names[0],
            'lastName' => $names[1],
            'name' => (string) ($lead['decision_maker'] ?? '') !== ''
                ? (string) $lead['decision_maker']
                : (string) $lead['company'],
            'email' => self::emailForPush($lead),
            'phone' => (string) ($lead['direct_phone'] ?? '') !== ''
                ? (string) $lead['direct_phone']
                : (string) ($lead['phone'] ?? ''),
            'companyName' => (string) $lead['company'],
            'website' => (string) ($lead['website'] ?? ''),
            'city' => self::cityOf((string) ($lead['market'] ?? '')),
            'state' => (string) ($lead['state'] ?? ''),
            'source' => 'Prospector',
            'tags' => array_values(array_filter([
                'prospector',
                strtolower(trim((string) ($lead['vertical'] ?? ''))) ?: null,
                (string) ($lead['door'] ?? '') !== '' ? 'door: ' . strtolower((string) $lead['door']) : null,
                'fit ' . (int) ($lead['fit_score'] ?? 0),
            ])),
        ], static fn ($v): bool => $v !== '' && $v !== null && $v !== []);

        // upsert matches on email/phone within the location, so re-pushing a
        // lead updates the existing contact instead of duplicating it.
        $response = $this->request('POST', '/contacts/upsert', $payload);

        if (!$response['ok']) {
            // Older sub-accounts may not expose /contacts/upsert; fall back to create.
            $response = $this->request('POST', '/contacts/', $payload);
            if (!$response['ok']) {
                return ['ok' => false, 'message' => $response['error']];
            }
        }

        $contactId = $response['data']['contact']['id']
            ?? $response['data']['id']
            ?? null;

        if (!is_string($contactId) || $contactId === '') {
            return ['ok' => false, 'message' => 'GoHighLevel accepted the contact but returned no ID.'];
        }

        $this->addNote($contactId, self::noteFor($lead));

        return ['ok' => true, 'contact_id' => $contactId, 'message' => 'Pushed to GoHighLevel'];
    }

    /** @return array{ok: bool, message: string} */
    public function addNote(string $contactId, string $body): array
    {
        $response = $this->request('POST', '/contacts/' . rawurlencode($contactId) . '/notes', [
            'body' => mb_substr($body, 0, 5000),
        ]);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Note added' : $response['error']];
    }

    /**
     * @return array{ok: bool, contacts: list<array<string, mixed>>, error: string}
     */
    public function contacts(int $limit = 25, string $query = ''): array
    {
        $params = ['locationId' => $this->locationId, 'limit' => max(1, min(100, $limit))];
        if ($query !== '') {
            $params['query'] = $query;
        }

        $response = $this->request('GET', '/contacts/?' . http_build_query($params));

        if (!$response['ok']) {
            return ['ok' => false, 'contacts' => [], 'error' => $response['error']];
        }

        $contacts = $response['data']['contacts'] ?? [];

        return [
            'ok' => true,
            'contacts' => is_array($contacts) ? array_values($contacts) : [],
            'error' => '',
        ];
    }

    /**
     * @return array{ok: bool, opportunities: list<array<string, mixed>>, error: string}
     */
    public function opportunities(int $limit = 25): array
    {
        $params = ['location_id' => $this->locationId, 'limit' => max(1, min(100, $limit))];

        $response = $this->request('GET', '/opportunities/search?' . http_build_query($params));

        if (!$response['ok']) {
            return ['ok' => false, 'opportunities' => [], 'error' => $response['error']];
        }

        $opportunities = $response['data']['opportunities'] ?? [];

        return [
            'ok' => true,
            'opportunities' => is_array($opportunities) ? array_values($opportunities) : [],
            'error' => '',
        ];
    }

    /** @return array{ok: bool, pipelines: list<array<string, mixed>>, error: string} */
    public function pipelines(): array
    {
        $response = $this->request(
            'GET',
            '/opportunities/pipelines?' . http_build_query(['locationId' => $this->locationId])
        );

        if (!$response['ok']) {
            return ['ok' => false, 'pipelines' => [], 'error' => $response['error']];
        }

        $pipelines = $response['data']['pipelines'] ?? [];

        return ['ok' => true, 'pipelines' => is_array($pipelines) ? array_values($pipelines) : [], 'error' => ''];
    }

    /**
     * Create an opportunity for a pushed contact, when a pipeline and stage are
     * configured.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, message: string}
     */
    public function createOpportunity(string $contactId, array $lead): array
    {
        $pipelineId = Settings::get('ghl_pipeline_id');
        $stageId = Settings::get('ghl_stage_id');

        if ($pipelineId === '' || $stageId === '') {
            return ['ok' => false, 'message' => 'No pipeline and stage configured.'];
        }

        $response = $this->request('POST', '/opportunities/', [
            'pipelineId' => $pipelineId,
            'pipelineStageId' => $stageId,
            'locationId' => $this->locationId,
            'contactId' => $contactId,
            'name' => (string) $lead['company'],
            'status' => 'open',
        ]);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Opportunity created' : $response['error']];
    }

    // ----------------------------------------------------------- contacts

    /** @return array{ok: bool, contact: array<string, mixed>, error: string} */
    public function contact(string $contactId): array
    {
        $response = $this->request('GET', '/contacts/' . rawurlencode($contactId));

        if (!$response['ok']) {
            return ['ok' => false, 'contact' => [], 'error' => $response['error']];
        }

        $contact = $response['data']['contact'] ?? $response['data'];

        return ['ok' => true, 'contact' => is_array($contact) ? $contact : [], 'error' => ''];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{ok: bool, message: string}
     */
    public function updateContact(string $contactId, array $fields): array
    {
        // locationId is rejected on update — GHL infers it from the contact.
        unset($fields['locationId']);

        $response = $this->request('PUT', '/contacts/' . rawurlencode($contactId), $fields);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Contact updated' : $response['error']];
    }

    /** @return array{ok: bool, notes: list<array<string, mixed>>, error: string} */
    public function notes(string $contactId): array
    {
        $response = $this->request('GET', '/contacts/' . rawurlencode($contactId) . '/notes');

        if (!$response['ok']) {
            return ['ok' => false, 'notes' => [], 'error' => $response['error']];
        }

        $notes = $response['data']['notes'] ?? [];

        return ['ok' => true, 'notes' => is_array($notes) ? array_values($notes) : [], 'error' => ''];
    }

    /** @return array{ok: bool, tasks: list<array<string, mixed>>, error: string} */
    public function tasks(string $contactId): array
    {
        $response = $this->request('GET', '/contacts/' . rawurlencode($contactId) . '/tasks');

        if (!$response['ok']) {
            return ['ok' => false, 'tasks' => [], 'error' => $response['error']];
        }

        $tasks = $response['data']['tasks'] ?? [];

        return ['ok' => true, 'tasks' => is_array($tasks) ? array_values($tasks) : [], 'error' => ''];
    }

    /** @return array{ok: bool, message: string} */
    public function addTask(string $contactId, string $title, string $dueDate = '', string $body = ''): array
    {
        $payload = array_filter([
            'title' => mb_substr($title, 0, 200),
            'body' => mb_substr($body, 0, 2000),
            // GHL wants an ISO timestamp and rejects the call without one, so
            // an unset due date becomes end of today rather than an error.
            'dueDate' => $dueDate !== '' ? $dueDate : Clock::local()->setTime(17, 0)->format('c'),
            'completed' => false,
        ], static fn (mixed $v): bool => $v !== '' && $v !== null);

        $payload['completed'] = false;

        $response = $this->request('POST', '/contacts/' . rawurlencode($contactId) . '/tasks', $payload);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Task added' : $response['error']];
    }

    /** @return array{ok: bool, message: string} */
    public function completeTask(string $contactId, string $taskId, bool $completed = true): array
    {
        $response = $this->request(
            'PUT',
            '/contacts/' . rawurlencode($contactId) . '/tasks/' . rawurlencode($taskId) . '/completed',
            ['completed' => $completed]
        );

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Task updated' : $response['error']];
    }

    // ------------------------------------------------------ opportunities

    /**
     * Opportunities for one pipeline, which is what the board renders. GHL caps
     * a page at 100; the board asks for that and says so when it is full rather
     * than paging, because a stage with more than 100 open deals is not a board
     * problem.
     *
     * @return array{ok: bool, opportunities: list<array<string, mixed>>, error: string}
     */
    public function opportunitiesForPipeline(string $pipelineId, int $limit = 100): array
    {
        $params = [
            'location_id' => $this->locationId,
            'pipeline_id' => $pipelineId,
            'limit' => max(1, min(100, $limit)),
        ];

        $response = $this->request('GET', '/opportunities/search?' . http_build_query($params));

        if (!$response['ok']) {
            return ['ok' => false, 'opportunities' => [], 'error' => $response['error']];
        }

        $found = $response['data']['opportunities'] ?? [];

        return ['ok' => true, 'opportunities' => is_array($found) ? array_values($found) : [], 'error' => ''];
    }

    /**
     * Move a card to another column. GHL requires the pipeline id on the update
     * even though the stage implies it.
     *
     * @return array{ok: bool, message: string}
     */
    public function moveOpportunity(string $opportunityId, string $pipelineId, string $stageId): array
    {
        $response = $this->request('PUT', '/opportunities/' . rawurlencode($opportunityId), [
            'pipelineId' => $pipelineId,
            'pipelineStageId' => $stageId,
        ]);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Moved' : $response['error']];
    }

    /** @return array{ok: bool, message: string} */
    public function setOpportunityStatus(string $opportunityId, string $status): array
    {
        $allowed = ['open', 'won', 'lost', 'abandoned'];
        if (!in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'Status must be one of: ' . implode(', ', $allowed)];
        }

        $response = $this->request(
            'PUT',
            '/opportunities/' . rawurlencode($opportunityId) . '/status',
            ['status' => $status]
        );

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Updated' : $response['error']];
    }

    // ------------------------------------------------------ conversations

    /**
     * @return array{ok: bool, conversations: list<array<string, mixed>>, error: string}
     */
    public function conversations(string $contactId = '', int $limit = 25): array
    {
        $params = ['locationId' => $this->locationId, 'limit' => max(1, min(100, $limit))];
        if ($contactId !== '') {
            $params['contactId'] = $contactId;
        }

        $response = $this->request('GET', '/conversations/search?' . http_build_query($params));

        if (!$response['ok']) {
            return ['ok' => false, 'conversations' => [], 'error' => $response['error']];
        }

        $found = $response['data']['conversations'] ?? [];

        return ['ok' => true, 'conversations' => is_array($found) ? array_values($found) : [], 'error' => ''];
    }

    /**
     * GHL has shipped this response both as {messages: [...]} and as
     * {messages: {messages: [...]}}, so both are unwrapped here.
     *
     * @return array{ok: bool, messages: list<array<string, mixed>>, error: string}
     */
    public function messages(string $conversationId, int $limit = 50): array
    {
        $response = $this->request(
            'GET',
            '/conversations/' . rawurlencode($conversationId) . '/messages?'
                . http_build_query(['limit' => max(1, min(100, $limit))])
        );

        if (!$response['ok']) {
            return ['ok' => false, 'messages' => [], 'error' => $response['error']];
        }

        $messages = $response['data']['messages'] ?? [];
        if (is_array($messages) && isset($messages['messages']) && is_array($messages['messages'])) {
            $messages = $messages['messages'];
        }

        return ['ok' => true, 'messages' => is_array($messages) ? array_values($messages) : [], 'error' => ''];
    }

    /**
     * Send an email or SMS to a contact. This reaches a real person, so the
     * caller is expected to have confirmed intent first.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendMessage(string $contactId, string $type, string $body, string $subject = ''): array
    {
        $type = strtoupper($type) === 'EMAIL' ? 'Email' : 'SMS';

        if (trim($body) === '') {
            return ['ok' => false, 'message' => 'Nothing to send — the message is empty.'];
        }

        $payload = [
            'type' => $type,
            'contactId' => $contactId,
            'message' => $body,
        ];

        if ($type === 'Email') {
            $payload['subject'] = $subject !== '' ? $subject : 'Message from 44i';
            $payload['html'] = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        }

        $response = $this->request('POST', '/conversations/messages', $payload);

        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['error']];
        }

        return ['ok' => true, 'message' => $type === 'Email' ? 'Email sent' : 'Text sent'];
    }

    // ---------------------------------------------- workflows and AI agents

    /** @return array{ok: bool, workflows: list<array<string, mixed>>, error: string} */
    public function workflows(): array
    {
        $response = $this->request(
            'GET',
            '/workflows/?' . http_build_query(['locationId' => $this->locationId])
        );

        if (!$response['ok']) {
            return ['ok' => false, 'workflows' => [], 'error' => $response['error']];
        }

        $workflows = $response['data']['workflows'] ?? [];

        return ['ok' => true, 'workflows' => is_array($workflows) ? array_values($workflows) : [], 'error' => ''];
    }

    /** @return array{ok: bool, message: string} */
    public function enrollInWorkflow(string $contactId, string $workflowId): array
    {
        $response = $this->request(
            'POST',
            '/contacts/' . rawurlencode($contactId) . '/workflow/' . rawurlencode($workflowId),
            ['eventStartTime' => Clock::local()->format('c')]
        );

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Added to the automation' : $response['error']];
    }

    /**
     * Take a contact back out of an automation.
     *
     * The counterpart to enrolling, and not optional: adding a hundred people
     * to the wrong workflow is a mistake somebody will make, and without this
     * the only fix is clicking through GoHighLevel one contact at a time.
     *
     * @return array{ok: bool, message: string}
     */
    public function removeFromWorkflow(string $contactId, string $workflowId): array
    {
        $response = $this->request(
            'DELETE',
            '/contacts/' . rawurlencode($contactId) . '/workflow/' . rawurlencode($workflowId)
        );

        return [
            'ok' => $response['ok'],
            'message' => $response['ok'] ? 'Removed from the automation' : $response['error'],
        ];
    }

    /**
     * The whole thread for one contact — every channel, oldest last.
     *
     * GoHighLevel keeps a contact's email and SMS in separate conversations, so
     * a rep looking at "the conversation" is really looking at two or three.
     * This stitches them into one list, because the question being asked is
     * "what have we said to this person", not "what is in conversation X".
     *
     * @return array{ok: bool, messages: list<array<string, mixed>>, conversations: int, error: string}
     */
    public function threadFor(string $contactId, int $limit = 50): array
    {
        $found = $this->conversations($contactId, 20);

        if (!$found['ok']) {
            return ['ok' => false, 'messages' => [], 'conversations' => 0, 'error' => $found['error']];
        }

        $messages = [];
        $errors = [];

        foreach ($found['conversations'] as $conversation) {
            $id = (string) ($conversation['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $batch = $this->messages($id, $limit);
            if (!$batch['ok']) {
                $errors[] = $batch['error'];
                continue;
            }

            foreach ($batch['messages'] as $message) {
                $message['conversationId'] = $id;
                $messages[] = $message;
            }
        }

        // Newest first, which is how the API returns each conversation and how
        // the screens read. Undated messages sort last rather than jumping to
        // the top on a timestamp of 0.
        usort($messages, static function (array $a, array $b): int {
            $left = strtotime((string) ($a['dateAdded'] ?? '')) ?: 0;
            $right = strtotime((string) ($b['dateAdded'] ?? '')) ?: 0;

            return $right <=> $left;
        });

        return [
            // A contact with no conversations yet is a success with nothing in
            // it, not a failure.
            'ok' => $messages !== [] || $errors === [],
            'messages' => array_slice($messages, 0, $limit),
            'conversations' => count($found['conversations']),
            'error' => $messages === [] && $errors !== [] ? $errors[0] : '',
        ];
    }

    /**
     * Conversation AI agents. Read-only here on purpose: the public API covers
     * creating and configuring agents, but nothing documented turns a bot on or
     * off for one contact or conversation, which is what a rep would actually
     * want mid-conversation.
     *
     * @return array{ok: bool, agents: list<array<string, mixed>>, error: string}
     */
    public function aiAgents(): array
    {
        $response = $this->request(
            'GET',
            '/conversation-ai/agents/search?' . http_build_query(['locationId' => $this->locationId])
        );

        if (!$response['ok']) {
            return ['ok' => false, 'agents' => [], 'error' => $response['error']];
        }

        $agents = $response['data']['agents'] ?? $response['data']['data'] ?? [];

        return ['ok' => true, 'agents' => is_array($agents) ? array_values($agents) : [], 'error' => ''];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, data: array<string, mixed>, error: string, status: int}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::base() . $path);
        if ($ch === false) {
            return ['ok' => false, 'data' => [], 'error' => 'Could not initialise the HTTP client.', 'status' => 0];
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Version: ' . self::VERSION,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_POSTFIELDS] = $encoded === false ? '{}' : $encoded;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'data' => [],
                'error' => 'Could not reach GoHighLevel: ' . ($curlError !== '' ? $curlError : 'unknown network error'),
                'status' => $status,
            ];
        }

        $decoded = json_decode((string) $raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $message = $data['message'] ?? $data['error'] ?? null;
            if (is_array($message)) {
                $message = implode('; ', array_map('strval', $message));
            }

            $detail = is_string($message) && $message !== ''
                ? $message
                : 'HTTP ' . $status . ' from GoHighLevel';

            if ($status === 401 || $status === 403) {
                $detail .= ' — check the private integration token and its scopes.';
            }

            return ['ok' => false, 'data' => $data, 'error' => $detail, 'status' => $status];
        }

        return ['ok' => true, 'data' => $data, 'error' => '', 'status' => $status];
    }

    /**
     * A pattern-confidence email has not been verified, so it must not be the
     * address a bulk sequence fires at. Push the lead without it and leave the
     * address in the note for a human to confirm.
     *
     * @param array<string, mixed> $lead
     */
    private static function emailForPush(array $lead): string
    {
        $email = trim((string) ($lead['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return ($lead['email_confidence'] ?? '') === 'pattern' ? '' : $email;
    }

    /** @param array<string, mixed> $lead */
    private static function noteFor(array $lead): string
    {
        $lines = ['Prospector lead — fit score ' . (int) ($lead['fit_score'] ?? 0)];

        $add = static function (string $label, mixed $value) use (&$lines): void {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        };

        $add('Vertical', $lead['vertical'] ?? null);
        $add('Buyer door', $lead['door'] ?? null);
        $add('Market', $lead['market'] ?? null);
        $add('Why them', $lead['why'] ?? null);
        $add('Opening hook', $lead['hook'] ?? null);
        $add('Website', $lead['website'] ?? null);
        $add('LinkedIn', $lead['linkedin'] ?? null);
        $add('Main phone', $lead['phone'] ?? null);
        $add('Source', $lead['source'] ?? null);

        if (($lead['email_confidence'] ?? '') === 'pattern' && ($lead['email'] ?? '') !== '') {
            $lines[] = 'Unverified pattern email (do NOT bulk send, verify first): ' . $lead['email'];
        } elseif (($lead['email'] ?? '') !== '') {
            $lines[] = 'Email confidence: ' . (string) ($lead['email_confidence'] ?? 'unknown');
        }

        $lines[] = 'Delivered ' . Clock::display((string) ($lead['created_at'] ?? Clock::now()), 'M j, Y');

        return implode("\n", $lines);
    }

    /** @return array{0: string, 1: string} */
    private static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = explode(' ', $name);
        $first = array_shift($parts) ?? '';

        return [$first, implode(' ', $parts)];
    }

    private static function cityOf(string $market): string
    {
        if ($market === '') {
            return '';
        }

        return trim(explode(',', $market)[0]);
    }
}
