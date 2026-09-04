<?php

declare(strict_types=1);

namespace Prospector\Http;

use Prospector\Auth;
use Prospector\Automations;
use Prospector\Claude;
use Prospector\Direct;
use Prospector\Emails;
use Prospector\Enrich;
use Prospector\GoHighLevel;
use Prospector\LeadForm;
use Prospector\LeadImport;
use Prospector\Leads;
use Prospector\LocalModel;
use Prospector\Mailer;
use Prospector\Outreach;
use Prospector\Prospector;
use Prospector\Runs;
use Prospector\Support\Background;
use Prospector\Support\Clock;
use Prospector\Support\Request;
use Prospector\Support\Settings;
use Prospector\Support\View;
use Prospector\Users;

final class Controller
{
    private const PER_PAGE = 40;

    // ---------------------------------------------------------------- auth

    public static function login(): void
    {
        if (Auth::check()) {
            self::redirect('/dashboard');
        }

        $email = '';
        $error = null;
        $needsPassword = false;

        if (Request::isPost()) {
            if (!Auth::verifyCsrf(Request::raw('csrf'))) {
                $error = 'Your session expired. Try again.';
            } else {
                $email = Request::input('email');
                $result = Auth::attempt($email, Request::raw('password'));

                if ($result['ok']) {
                    self::redirect('/dashboard');
                }

                $error = $result['error'] ?? 'Sign-in failed.';
                $needsPassword = (bool) ($result['needs_password'] ?? false);
            }
        }

        View::render('login', [
            'error' => $error,
            'email' => $email,
            'needsPassword' => $needsPassword,
            'csrf' => Auth::csrfToken(),
        ]);
    }

    public static function logout(): void
    {
        Auth::logout();
        self::redirect('/login');
    }

    // ----------------------------------------------------------- dashboard

    public static function dashboard(): void
    {
        self::requireLogin();

        $scope = self::scopeUserId();
        $stats = Leads::stats($scope);
        $recent = Leads::search(['user_id' => $scope, 'sort' => 'newest'], 8);
        $priority = Leads::search(
            ['user_id' => $scope, 'status' => 'new', 'sort' => 'score'],
            6
        );

        $todaysRuns = [];
        $users = Auth::isAdmin() ? Users::all(true) : [Auth::user()];
        foreach ($users as $user) {
            if ($user === null) {
                continue;
            }
            if ((string) $user['loop'] === 'none') {
                continue;
            }
            $todaysRuns[] = [
                'user' => $user,
                'run' => Runs::forUserOnDate((int) $user['id'], Clock::today()),
            ];
        }

        View::page('dashboard', [
            'title' => 'Home',
            'stats' => $stats,
            'recent' => $recent,
            'priority' => $priority,
            'todaysRuns' => $todaysRuns,
            'volume' => Leads::dailyVolume($scope, 14),
            'runs' => Runs::recent($scope, 5),
            'scheduleText' => Mailer::scheduleDescription(),
        ]);
    }

    // --------------------------------------------------------------- leads

    public static function leads(): void
    {
        self::requireLogin();

        $filters = self::leadFilters();
        $page = max(1, Request::int('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $total = Leads::count($filters);
        $leads = Leads::search($filters, self::PER_PAGE, $offset);

        View::page('leads', [
            'title' => 'Leads',
            'leads' => $leads,
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'facets' => Leads::facets(self::scopeUserId()),
            'owners' => Auth::isAdmin() ? Users::all() : [],
            // Scoped the same way the workflow list is. An admin filtered to
            // Darren is working in Darren's account, and gating the bulk actions
            // on the admin's own connection — which they usually do not have —
            // hid actions that would have worked perfectly well.
            'ghlReady' => GoHighLevel::forUser(self::scopedOwner()) !== null,
            'workflows' => self::workflowsForBulk(),
        ]);
    }

    /**
     * Whose GoHighLevel account the current screen is working in.
     *
     * @return array<string, mixed>|null
     */
    private static function scopedOwner(): ?array
    {
        $scope = self::scopeUserId();

        return $scope !== null ? Users::find($scope) : Auth::user();
    }

    /**
     * Automations offered in the bulk bar.
     *
     * A workflow belongs to one GoHighLevel sub-account, so there is no single
     * list that works across owners: offering Billy's automations for Darren's
     * leads would fail one row at a time. So the list comes from whichever
     * account the screen is actually scoped to — an admin filtered to Darren
     * gets Darren's, and an admin looking at everybody gets their own, which is
     * usually nothing. That is the honest answer: you cannot bulk-enrol across
     * sub-accounts, and an empty list says so by leaving the option out.
     *
     * @return list<array<string, mixed>>
     */
    private static function workflowsForBulk(): array
    {
        $client = GoHighLevel::forUser(self::scopedOwner());
        if ($client === null) {
            return [];
        }

        $result = $client->workflows();

        return $result['ok'] ? $result['workflows'] : [];
    }

    public static function lead(int $id): void
    {
        self::requireLogin();

        $lead = Leads::find($id);
        if ($lead === null) {
            self::notFound('That lead does not exist.');
        }

        if (!Auth::canAccessUser((int) $lead['user_id'])) {
            self::forbidden();
        }

        self::renderLead($lead);
    }

    /**
     * Render the lead screen. Shared by the normal view and by a dig, which
     * needs to show its findings on the same page rather than redirecting.
     *
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $extra
     */
    private static function renderLead(array $lead, array $extra = []): void
    {
        $id = (int) $lead['id'];
        $digState = Leads::digState($lead);
        $owner = Users::find((int) $lead['user_id']);

        View::page('lead', array_merge([
            'title' => (string) $lead['company'],
            'lead' => $lead,
            'activities' => Leads::activities($id),
            'owners' => Auth::isAdmin() ? Users::all() : [],
            'ghlReady' => GoHighLevel::forUser($owner) !== null,
            // Everything the compose box needs to explain itself before anyone
            // types: whether this owner can send at all, whether this lead can
            // be reached each way, and how it will be signed.
            'sendAs' => $owner,
            'canSend' => Direct::available($owner),
            'canEmail' => Direct::reachable($lead, 'Email'),
            'canText' => Direct::reachable($lead, 'SMS'),
            'signature' => Direct::signature($owner),
            'signatureHtml' => \Prospector\Signature::html(Direct::signature($owner)),
            'fromAddress' => Direct::fromAddress($owner),
            'defaultSubject' => Direct::defaultSubject($lead),
            'run' => $lead['run_id'] !== null ? Runs::find((int) $lead['run_id']) : null,
            'digStatus' => $digState['status'],
            'dig' => $digState['findings'],
            'digMessage' => $digState['message'],
            'opener' => Emails::forStep($id, 1),
            'cadenceSteps' => Outreach::steps(),
            'deliverable' => Outreach::deliverability($lead),
            'unverifiedEmail' => Outreach::isUnverified($lead),
            'enrolments' => Automations::enrolmentsFor($id),
        ] + self::conversationFor($lead), $extra));
    }

    /**
     * The GoHighLevel thread and workflow list for a lead.
     *
     * Both are live API calls, so they are only made when there is a contact to
     * ask about and a connection to ask over. A lead that has never been pushed
     * has nothing to show, and calling anyway would just slow the page down to
     * render an empty box.
     *
     * @param array<string, mixed> $lead
     * @return array<string, mixed>
     */
    private static function conversationFor(array $lead): array
    {
        $blank = ['thread' => [], 'threadError' => null, 'workflows' => []];

        if (($lead['ghl_contact_id'] ?? null) === null) {
            return $blank;
        }

        $client = GoHighLevel::forUser(Users::find((int) $lead['user_id']));
        if ($client === null) {
            return $blank;
        }

        $thread = $client->threadFor((string) $lead['ghl_contact_id'], 30);
        $workflows = $client->workflows();

        return [
            'thread' => $thread['messages'],
            'threadError' => $thread['ok'] ? null : $thread['error'],
            'workflows' => $workflows['ok'] ? $workflows['workflows'] : [],
        ];
    }

    public static function leadAction(int $id, string $action): void
    {
        self::requireLogin();
        self::requireCsrf();

        $lead = Leads::find($id);
        if ($lead === null) {
            self::notFound('That lead does not exist.');
        }
        if (!Auth::canAccessUser((int) $lead['user_id'])) {
            self::forbidden();
        }

        $back = Request::input('return', '/leads/' . $id);

        switch ($action) {
            case 'status':
                $status = Request::input('status');
                $note = Request::input('note');
                if (Leads::setStatus($id, $status, $note !== '' ? $note : null, Auth::id())) {
                    self::flash('success', 'Marked as ' . Leads::statusLabel($status) . '.');
                } else {
                    self::flash('error', 'That status is not valid.');
                }
                break;

            case 'note':
                $note = Request::input('note');
                if ($note === '') {
                    self::flash('error', 'Write something first.');
                } else {
                    Leads::addActivity($id, Auth::id(), 'note', $note);
                    self::flash('success', 'Note added.');
                }
                break;

            case 'archive':
                Leads::archive($id, Auth::id());
                self::flash('success', 'Lead archived.');
                $back = Request::input('return', '/leads');
                break;

            case 'restore':
                Leads::restore($id, Auth::id());
                self::flash('success', 'Lead restored.');
                break;

            case 'delete':
                // Deleting the lead we are looking at means the return URL is
                // about to 404, so this one ignores it and goes to the list.
                if (Leads::delete($id)) {
                    self::flash('success', $lead['company'] . ' deleted.');
                } else {
                    self::flash('error', 'That lead was already gone.');
                }
                self::redirect('/leads');

                // no break — redirect() exits

            case 'reassign':
                if (!Auth::isAdmin()) {
                    self::forbidden();
                }
                Leads::reassign($id, Request::int('owner_id'), Auth::id());
                self::flash('success', 'Owner updated.');
                break;

            case 'ghl':
                $result = self::pushLeadToGhl($id);
                self::flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'reply':
                self::replyToLead($lead);
                break;

            case 'enrol':
                $result = Automations::enrol(
                    $lead,
                    Request::input('workflow_id'),
                    Request::input('workflow_name'),
                    'manual',
                    null,
                    Auth::id()
                );
                self::flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'unenrol':
                $result = Automations::remove($lead, Request::input('workflow_id'), Auth::id());
                self::flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'dig':
                self::startDig($id, $lead);

                return;

            case 'dig-apply':
                self::applyDig($id, $lead);
                Leads::clearDig($id);
                break;

            case 'dig-dismiss':
                Leads::clearDig($id);
                break;

            default:
                self::flash('error', 'Unknown action.');
        }

        self::redirect($back);
    }

    /**
     * Start a dig and get out of the browser's way.
     *
     * A dig takes 30-60 seconds. Held open, that outlasts the request timeout on
     * shared hosting — the connection dies, and the half-finished POST leaves
     * the browser sitting on /leads/{id}/dig, which is a 404 on a GET. So the
     * response goes out first and the work happens after it.
     *
     * @param array<string, mixed> $lead
     */
    private static function startDig(int $id, array $lead): never
    {
        Leads::startDig($id);

        $back = View::url('leads/' . $id);

        if (!Background::canDetach()) {
            // No FastCGI to hand the response to, so run it inline and hope the
            // host is patient. Still redirects, so a refresh cannot re-post.
            Background::extendLimits(300);
            $dug = Enrich::dig($lead);
            Leads::finishDig($id, $dug['ok'], $dug['findings'], $dug['message']);
            self::redirect('/leads/' . $id);
        }

        // 303 so the browser follows with a GET — the lead page then polls.
        Background::respondThenContinue('', 303, ['Location' => $back]);

        // The session lock would otherwise block every poll request for the
        // whole dig, which would look exactly like the hang this replaces.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Background::extendLimits(600);

        try {
            $dug = Enrich::dig($lead);
            Leads::finishDig($id, $dug['ok'], $dug['findings'], $dug['message']);
            Background::log('Dig for lead ' . $id . ': ' . $dug['message']);
        } catch (\Throwable $e) {
            Leads::finishDig($id, false, [], 'The dig failed: ' . $e->getMessage());
            Background::log('Dig for lead ' . $id . ' failed: ' . $e->getMessage());
        }

        exit;
    }

    /**
     * Write the fields the rep ticked. Values travel in hidden inputs from the
     * findings panel, so only what was shown can be applied — and each one is
     * recorded on the timeline with the URL it came from, because in six weeks
     * "where did this address come from" is the question that matters.
     *
     * @param array<string, mixed> $lead
     */
    private static function applyDig(int $id, array $lead): void
    {
        $map = Enrich::fieldMap();
        $chosen = [];
        $noteLines = [];

        foreach ($map as $label => $column) {
            $key = str_replace(' ', '_', $label);

            if (!Request::bool('apply_' . $key)) {
                continue;
            }

            $value = trim(Request::raw('value_' . $key));
            if ($value === '') {
                continue;
            }

            $chosen[$column] = $value;

            $source = trim(Request::raw('source_' . $key));
            $noteLines[] = ucfirst($label) . ': ' . $value
                . ($source !== '' ? ' — found at ' . $source : ' — no source recorded');
        }

        if ($chosen === []) {
            self::flash('error', 'Nothing was ticked, so nothing changed.');

            return;
        }

        // An email arriving from a dig carries its confidence with it. Without
        // one it is treated as unverified, which keeps it out of the
        // GoHighLevel email field until someone confirms it.
        if (isset($chosen['email'])) {
            $confidence = Request::input('confidence_email');
            $chosen['email_confidence'] = in_array($confidence, ['verified', 'high', 'pattern'], true)
                ? $confidence
                : 'pattern';
            $noteLines[] = 'Email confidence: ' . $chosen['email_confidence'];
        }

        Leads::updateFields($id, $chosen);
        Leads::addActivity(
            $id,
            Auth::id(),
            'note',
            "Contact details added by a dig:\n" . implode("\n", $noteLines)
        );

        self::flash('success', 'Saved ' . implode(', ', array_keys($chosen)) . '.');
    }

    /**
     * Reply to a lead in the GoHighLevel thread.
     *
     * Deliberately not routed through the cadence: this is a person typing an
     * answer to something that came back, which has nothing to do with the
     * approved sequence and should not disturb it.
     *
     * @param array<string, mixed> $lead
     */
    /**
     * Send one message to a lead, written on the spot.
     *
     * The rules all live in Direct — who may send, who can be reached, what
     * happens when the lead is not in GoHighLevel yet. This reads the form and
     * reports the answer.
     *
     * @param array<string, mixed> $lead
     */
    private static function replyToLead(array $lead): void
    {
        $result = Direct::send($lead, [
            'channel' => Request::input('channel'),
            'to' => Request::input('to'),
            'subject' => Request::input('subject'),
            'body' => Request::raw('body'),
        ], Auth::id());

        self::flash($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public static function leadsBulk(): void
    {
        self::requireLogin();
        self::requireCsrf();

        $ids = Request::ints('ids');
        $action = Request::input('bulk_action');
        $back = Request::input('return', '/leads');

        if ($ids === [] || $action === '') {
            self::flash('error', 'Pick at least one lead and an action.');
            self::redirect($back);
        }

        $done = 0;
        $deleted = 0;
        $failed = 0;
        $messages = [];

        // Enrolling needs a workflow picked; without one there is nothing to
        // add anybody to, and every row would fail identically.
        $workflowId = Request::input('workflow_id');
        if ($action === 'enrol' && $workflowId === '') {
            self::flash('error', 'Pick an automation to add them to.');
            self::redirect($back);
        }

        foreach ($ids as $id) {
            $lead = Leads::find($id);
            if ($lead === null || !Auth::canAccessUser((int) $lead['user_id'])) {
                $failed++;
                continue;
            }

            if ($action === 'archive') {
                Leads::archive($id, Auth::id());
                $done++;
            } elseif ($action === 'restore') {
                Leads::restore($id, Auth::id());
                $done++;
            } elseif ($action === 'delete') {
                if (Leads::delete($id)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            } elseif ($action === 'enrol') {
                $result = Automations::enrol(
                    $lead,
                    $workflowId,
                    Request::input('workflow_name'),
                    'manual',
                    null,
                    Auth::id()
                );
                if ($result['ok']) {
                    $done++;
                } else {
                    $failed++;
                    $messages[$result['message']] = true;
                }
            } elseif ($action === 'ghl') {
                $result = self::pushLeadToGhl($id);
                if ($result['ok']) {
                    $done++;
                } else {
                    $failed++;
                    $messages[$result['message']] = true;
                }
            } elseif (array_key_exists($action, Leads::STATUSES)) {
                Leads::setStatus($id, $action, null, Auth::id());
                $done++;
            } else {
                $failed++;
            }
        }

        // "Deleted" and "updated" are different enough that reporting them as
        // one number would be misleading about what just happened.
        $summary = $deleted > 0
            ? $deleted . ' ' . ($deleted === 1 ? 'lead' : 'leads') . ' deleted'
            : $done . ' ' . ($done === 1 ? 'lead' : 'leads') . ' updated';

        if ($failed > 0) {
            $summary .= ', ' . $failed . ' skipped';
            if ($messages !== []) {
                $summary .= ' (' . implode('; ', array_slice(array_keys($messages), 0, 2)) . ')';
            }
        }

        self::flash($done + $deleted > 0 ? 'success' : 'error', $summary . '.');
        self::redirect($back);
    }

    /**
     * Upload a lead list: CSV or JSON, parsed and shown back before anything is
     * stored. Three states on one route — empty form, preview, commit.
     *
     * Uploads deliberately do NOT apply the fit-score floor. That floor exists
     * to stop a research engine padding a batch to hit a number; a person
     * uploading a file has already made that judgement.
     */
    /**
     * Add one lead by hand.
     *
     * The third door into the leads table, next to the daily batch and the
     * uploader, and the one for a lead that came out of a conversation — met
     * at a conference, passed on by a client, phoned in. LeadForm holds the
     * field list and the rules; this only decides whose it is, where it hangs,
     * and where you go next.
     */
    public static function leadsNew(): void
    {
        self::requireLogin();

        $owners = Auth::isAdmin() ? Users::all() : [];

        $render = static function (array $values, array $errors, int $targetUserId) use ($owners): void {
            View::page('leads_new', [
                'title' => 'New lead',
                'owners' => $owners,
                'groups' => LeadForm::groups(),
                'values' => $values,
                'errors' => $errors,
                'targetUserId' => $targetUserId,
            ]);
        };

        if (!Request::isPost()) {
            // importTarget reads user_id from the query too, so "save and add
            // another" comes back still pointed at the same person's account.
            $render(LeadForm::blank(), [], (int) self::importTarget()['id']);

            return;
        }

        self::requireCsrf();

        $owner = self::importTarget();
        $ownerId = (int) $owner['id'];
        $values = LeadForm::read(static fn (string $field): string => Request::raw($field));
        $checked = LeadForm::validate($values);

        if ($checked['errors'] !== []) {
            $render($values, $checked['errors'], $ownerId);

            return;
        }

        // Say which record it collides with rather than just refusing. Two
        // people at one company are fine; the same person twice is not, and
        // when it happens the useful thing is a way to get to the first one.
        $clash = Leads::findDuplicate($ownerId, $checked['lead']);

        if ($clash !== null) {
            $who = trim((string) ($clash['decision_maker'] ?? ''));
            $render(
                $values,
                ['company' => ($who !== '' ? $who : 'Somebody with no name')
                    . ' at ' . (string) $clash['company'] . ' is already on file for '
                    . (string) $owner['name'] . ' — lead #' . (int) $clash['id'] . '.'],
                $ownerId
            );

            return;
        }

        $loop = (string) $owner['loop'];
        if (!Users::isRunnableLoop($loop)) {
            $loop = 'partner';
        }

        $runId = Runs::handEntered($ownerId, $loop, Clock::today());
        $id = Leads::create($ownerId, $runId, $checked['lead']);

        if ($id === 0) {
            self::flash('error', 'That lead could not be saved. Nothing was stored.');
            $render($values, [], $ownerId);

            return;
        }

        Runs::recount($runId);
        Leads::addActivity($id, Auth::id(), 'created', 'Added by hand by ' . (string) (Auth::user()['name'] ?? 'someone'));

        // Deliberately after the lead exists: setStatus writes its own activity
        // entry and fires the disposition automations, which is exactly what
        // should happen when someone records a lead they have already spoken to.
        if ($checked['status'] !== 'new') {
            Leads::setStatus($id, $checked['status'], null, Auth::id());
        }

        $label = trim((string) ($checked['lead']['decision_maker'] ?? '')) !== ''
            ? (string) $checked['lead']['decision_maker'] . ' at ' . (string) $checked['lead']['company']
            : (string) $checked['lead']['company'];

        // Entering a stack of cards after an event is the normal case, so
        // "save and add another" comes back to an empty form rather than
        // making someone navigate back for each one.
        if (Request::raw('and_another') !== '') {
            self::flash('success', 'Saved ' . $label . '. Next one.');
            self::redirect('/leads/new' . ($ownerId !== Auth::id() ? '?user_id=' . $ownerId : ''));
        }

        self::flash('success', 'Added ' . $label . '.');
        self::redirect('/leads/' . $id);
    }

    public static function leadsImport(): void
    {
        self::requireLogin();

        $owners = Auth::isAdmin() ? Users::all() : [];

        $render = static function (array $extra) use ($owners): void {
            View::page('leads_import', array_merge([
                'title' => 'Upload leads',
                'owners' => $owners,
                'rows' => [],
                'problems' => [],
                'columns' => [],
                'ignored' => [],
                'raw' => '',
                'targetUserId' => Auth::id(),
                'sendEmail' => false,
                'fields' => LeadImport::fields(),
            ], $extra));
        };

        if (!Request::isPost()) {
            $render([]);

            return;
        }

        self::requireCsrf();

        $target = self::importTarget();
        $sendEmail = Request::bool('send_email');

        // Second pass: the rows have already been parsed and shown, and the
        // hidden field carries exactly what the preview displayed.
        if (Request::raw('confirm') === '1') {
            /** @var mixed $decoded */
            $decoded = json_decode(Request::raw('parsed'), true);
            $rows = is_array($decoded) ? $decoded : [];

            if ($rows === []) {
                self::flash('error', 'Nothing was imported — the confirmed list was empty.');
                self::redirect('/leads/import');
            }

            $result = self::storeImported($target, $rows, $sendEmail);

            self::flash(
                $result['stored'] > 0 ? 'success' : 'error',
                $result['message']
            );
            self::redirect($result['stored'] > 0 ? '/leads' : '/leads/import');
        }

        // First pass: read the file if one came, otherwise the pasted box.
        $raw = '';
        $fromFile = false;
        $upload = $_FILES['file'] ?? null;

        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $size = (int) ($upload['size'] ?? 0);
            if ($size > 2 * 1024 * 1024) {
                self::flash('error', 'That file is larger than 2MB. Split it, or paste the rows instead.');
                self::redirect('/leads/import');
            }
            $contents = @file_get_contents((string) $upload['tmp_name']);
            $raw = $contents === false ? '' : $contents;
            $fromFile = trim($raw) !== '';
        }

        if (trim($raw) === '') {
            $raw = Request::raw('raw');
        }

        $parsed = LeadImport::parse($raw);

        $render([
            'rows' => $parsed['rows'],
            'problems' => $parsed['problems'],
            'columns' => $parsed['columns'],
            'ignored' => $parsed['ignored'],
            // Only echo back what was typed. Replaying an uploaded file into the
            // textarea would be unreadable for anything but a tiny list.
            'raw' => $fromFile ? '' : $raw,
            'targetUserId' => (int) $target['id'],
            'sendEmail' => $sendEmail,
        ]);
    }

    /**
     * Whose leads these become. An admin may import for anyone; everyone else
     * imports for themselves whatever they post.
     *
     * @return array<string, mixed>
     */
    private static function importTarget(): array
    {
        $self = Auth::user();

        if ($self === null) {
            self::redirect('/login');
        }

        if (!Auth::isAdmin()) {
            return $self;
        }

        $requested = Request::int('user_id', 0);
        if ($requested <= 0) {
            return $self;
        }

        return Users::find($requested) ?? $self;
    }

    /**
     * @param array<string, mixed> $user
     * @param list<mixed>          $rows
     * @return array{stored: int, skipped: int, message: string}
     */
    private static function storeImported(array $user, array $rows, bool $sendEmail): array
    {
        // An upload still gets a run row to hang the leads off, and a user
        // with no loop can still be uploaded to.
        $loop = (string) $user['loop'];
        if (!Users::isRunnableLoop($loop)) {
            $loop = 'partner';
        }

        $runId = Runs::start(
            (int) $user['id'],
            $loop,
            Clock::today(),
            'upload',
            'Uploaded list',
            '',
            'upload'
        );

        $stored = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['company'] ?? '')) === '') {
                $skipped++;
                continue;
            }

            $id = Leads::create((int) $user['id'], $runId, $row);

            if ($id > 0) {
                $stored++;
                Leads::addActivity($id, Auth::id(), 'created', 'Added from an uploaded list');
            } else {
                // Already on file for this owner — de-duplication is the point.
                $skipped++;
            }
        }

        $brief = "## Uploaded list\n\n"
            . '- Imported by: ' . (string) (Auth::user()['name'] ?? 'unknown') . "\n"
            . '- Stored: ' . $stored . "\n"
            . '- Skipped as already on file: ' . $skipped . "\n";

        Runs::finish($runId, [
            'status' => $stored > 0 ? 'success' : 'partial',
            'lead_count' => $stored,
            'brief_md' => $brief,
        ]);

        $emailNote = '';
        if ($sendEmail && $stored > 0) {
            $run = Runs::find($runId);
            if ($run !== null) {
                $mail = Mailer::sendDailyBrief($user, $run, Leads::forRun($runId));
                if ($mail['ok']) {
                    Runs::markEmailed($runId);
                    $emailNote = ' Emailed to ' . (string) $user['email'] . '.';
                } else {
                    $emailNote = ' The email failed: ' . $mail['message'];
                }
            }
        }

        $message = $stored === 0
            ? 'Nothing new was imported — all ' . $skipped . ' were already on file for ' . (string) $user['name'] . '.'
            : sprintf(
                'Imported %d lead%s for %s.%s%s',
                $stored,
                $stored === 1 ? '' : 's',
                (string) $user['name'],
                $skipped > 0 ? ' ' . $skipped . ' skipped as already on file.' : '',
                $emailNote
            );

        return ['stored' => $stored, 'skipped' => $skipped, 'message' => $message];
    }

    public static function leadsExport(): void
    {
        self::requireLogin();

        $filters = self::leadFilters();
        $leads = Leads::search($filters, 5000);

        $filename = 'prospector-leads-' . Clock::today() . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fputcsv($out, [
            'Company', 'Website', 'Vertical', 'Door', 'Market', 'State',
            'Decision maker', 'Title', 'Email', 'Email confidence', 'Direct phone',
            'Main phone', 'LinkedIn', 'Fit score', 'Why them', 'Opening hook',
            'Status', 'Owner', 'In GoHighLevel', 'Delivered',
        ]);

        foreach ($leads as $lead) {
            fputcsv($out, [
                $lead['company'],
                $lead['website'],
                $lead['vertical'],
                $lead['door'],
                $lead['market'],
                $lead['state'],
                $lead['decision_maker'],
                $lead['title'],
                $lead['email'],
                $lead['email_confidence'],
                $lead['direct_phone'],
                $lead['phone'],
                $lead['linkedin'],
                $lead['fit_score'],
                $lead['why'],
                $lead['hook'],
                Leads::statusLabel((string) $lead['status']),
                $lead['owner_name'],
                $lead['ghl_contact_id'] !== null ? 'yes' : 'no',
                Clock::display((string) $lead['created_at'], 'Y-m-d'),
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Hand back an example import file — the fastest way to answer "what
     * columns does it want?" is to let someone open one and look.
     *
     * The rows come from LeadImport so the headers are always the real field
     * names, and both formats are built from the same data: a CSV and a JSON
     * download of the same file describe the same thing.
     */
    public static function leadsSample(string $format): void
    {
        self::requireLogin();

        $rows = LeadImport::sample();

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="prospector-sample-leads.json"');

            // Blank strings are dropped rather than shipped as "": an example
            // should show what an omitted field looks like, which is omitted.
            $clean = array_map(
                static fn (array $row): array => array_filter(
                    $row,
                    static fn (string|int $value): bool => $value !== '' && $value !== null
                ),
                $rows
            );

            echo json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prospector-sample-leads.csv"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // The BOM is what makes Excel open a UTF-8 CSV without mangling it, and
        // this file exists to be opened in Excel.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }

        fclose($out);
        exit;
    }

    // ---------------------------------------------------------------- runs

    public static function runs(): void
    {
        self::requireLogin();

        View::page('runs', [
            'title' => 'Batches',
            'runs' => Runs::recent(self::scopeUserId(), 60),
            'loopUsers' => self::runnableUsers(),
            'scheduleText' => Mailer::scheduleDescription(),
            'canDetach' => Background::canDetach(),
        ]);
    }

    public static function run(int $id): void
    {
        self::requireLogin();

        $run = Runs::find($id);
        if ($run === null) {
            self::notFound('That batch does not exist.');
        }
        if (!Auth::canAccessUser((int) $run['user_id'])) {
            self::forbidden();
        }

        View::page('run', [
            'title' => Runs::loopLabel((string) $run['loop']) . ' — ' . Clock::display((string) $run['started_at'], 'M j, Y'),
            'run' => $run,
            'leads' => Leads::forRun($id),
        ]);
    }

    public static function runStart(): void
    {
        self::requireLogin();
        self::requireCsrf();

        $userId = Request::int('user_id', Auth::id());

        if (!Auth::canAccessUser($userId)) {
            self::forbidden();
        }

        $user = Users::find($userId);
        if ($user === null) {
            self::flash('error', 'No such user.');
            self::redirect('/runs');
        }

        if ((string) $user['loop'] === 'none') {
            self::flash('error', $user['name'] . ' has no loop assigned. Set one on the Users screen first.');
            self::redirect('/runs');
        }

        if (Settings::anthropicKey() === '') {
            self::flash('error', 'Add an Anthropic API key under Settings before running a batch.');
            self::redirect('/settings');
        }

        if (Runs::isRunning($userId)) {
            self::flash('error', 'A batch is already running for ' . $user['name'] . '.');
            self::redirect('/runs');
        }

        $sendEmail = Request::bool('send_email');

        // Research takes minutes. Hand the browser a redirect immediately where
        // the host allows it, then keep working.
        if (Background::canDetach()) {
            self::flash(
                'success',
                'Batch started for ' . $user['name'] . '. It takes a few minutes — this page will show '
                . 'the result when it finishes.'
            );

            Background::respondThenContinue('', 302, ['Location' => View::url('runs')]);
            Background::extendLimits(1800);

            $result = Prospector::runFor($user, 'manual', $sendEmail);
            Background::log('Manual run for ' . $user['name'] . ': ' . $result['message']);
            exit;
        }

        Background::extendLimits(1800);
        $result = Prospector::runFor($user, 'manual', $sendEmail);
        self::flash($result['ok'] ? 'success' : 'error', $user['name'] . ': ' . $result['message']);
        self::redirect($result['run_id'] !== null ? '/runs/' . $result['run_id'] : '/runs');
    }

    // ------------------------------------------------------- gohighlevel

    public static function ghl(): void
    {
        self::requireLogin();

        $user = Auth::user();
        $viewUserId = Auth::isAdmin() ? Request::int('user_id', 0) : Auth::id();
        $viewUser = $viewUserId > 0 ? Users::find($viewUserId) : $user;
        $client = GoHighLevel::forUser($viewUser);

        $contacts = [];
        $opportunities = [];
        $error = null;
        $connection = null;

        if ($client === null) {
            $error = 'GoHighLevel is not connected yet. Add a private integration token and location ID '
                . (Auth::isAdmin() ? 'under Settings.' : '— ask Scott to set it up.');
        } else {
            $connection = $client->testConnection();

            if ($connection['ok']) {
                $search = Request::input('q');
                $contactResult = $client->contacts(25, $search);
                if ($contactResult['ok']) {
                    $contacts = $contactResult['contacts'];
                } else {
                    $error = $contactResult['error'];
                }

                $oppResult = $client->opportunities(25);
                if ($oppResult['ok']) {
                    $opportunities = $oppResult['opportunities'];
                } elseif ($error === null) {
                    $error = $oppResult['error'];
                }
            } else {
                $error = $connection['message'];
            }
        }

        View::page('ghl', [
            'title' => 'GoHighLevel',
            'contacts' => $contacts,
            'opportunities' => $opportunities,
            'error' => $error,
            'connection' => $connection,
            'viewUser' => $viewUser,
            'owners' => Auth::isAdmin() ? Users::all() : [],
            'pending' => Leads::count([
                'user_id' => self::scopeUserId(),
                'in_ghl' => 'no',
                'open_only' => true,
            ]),
        ]);
    }

    // ----------------------------------------------------------- settings

    public static function settings(): void
    {
        self::requireLogin();

        if (!Auth::isAdmin()) {
            self::forbidden('Only an admin can change settings.');
        }

        if (Request::isPost()) {
            self::requireCsrf();
            self::saveSettings();
            self::redirect('/settings');
        }

        View::page('settings', [
            'title' => 'Settings',
            'settings' => Settings::all(),
            'cronUrl' => View::url('cron.php', ['token' => Settings::cronToken()]),
            'canDetach' => Background::canDetach(),
            'envKey' => is_string(getenv('ANTHROPIC_API_KEY')) && getenv('ANTHROPIC_API_KEY') !== '',
            'workerToken' => Settings::workerToken(),
            'digModel' => Enrich::model(),
            'outreachModel' => Outreach::model(),
            'workerLastSeen' => Settings::get('worker_last_seen'),
            'workerStale' => self::workerIsStale(),
            'scheduleText' => Mailer::scheduleDescription(),
            'timezone' => Clock::timezoneName(),
        ]);
    }

    private static function saveSettings(): void
    {
        $values = [
            'mail_from_email' => Request::input('mail_from_email'),
            'mail_from_name' => Request::input('mail_from_name'),
            'mail_transport' => Request::input('mail_transport') === 'smtp' ? 'smtp' : 'mail',
            'smtp_host' => Request::input('smtp_host'),
            'smtp_port' => (string) Request::int('smtp_port', 587),
            'smtp_secure' => Request::input('smtp_secure'),
            'smtp_username' => Request::input('smtp_username'),
            'ghl_location_id' => Request::input('ghl_location_id'),
            'ghl_pipeline_id' => Request::input('ghl_pipeline_id'),
            'ghl_stage_id' => Request::input('ghl_stage_id'),
            'ghl_auto_push' => Request::bool('ghl_auto_push') ? '1' : '0',
            'run_hour' => (string) max(0, min(23, Request::int('run_hour', 7))),
            'run_minute' => (string) max(0, min(59, Request::int('run_minute', 30))),
            'run_weekdays_only' => Request::bool('run_weekdays_only') ? '1' : '0',
            'batch_size' => (string) max(1, min(25, Request::int('batch_size', 10))),
            'min_fit_score' => (string) max(0, min(100, Request::int('min_fit_score', 70))),
            'effort' => in_array(Request::input('effort'), ['low', 'medium', 'high', 'xhigh', 'max'], true)
                ? Request::input('effort')
                : 'high',
            'engine' => in_array(Request::input('engine'), ['api', 'worker', 'manual'], true)
                ? Request::input('engine')
                : 'api',
            'dig_model' => in_array(Request::input('dig_model'), Enrich::MODELS, true)
                ? Request::input('dig_model')
                : 'claude-sonnet-5',
            'outreach_model' => in_array(Request::input('outreach_model'), Outreach::MODELS, true)
                ? Request::input('outreach_model')
                : 'claude-sonnet-5',
            // Normalised on the way in so however the address was typed, what
            // is stored is what gets called.
            'local_model_url' => LocalModel::normaliseUrl(Request::input('local_model_url')),
            'local_model_name' => trim(Request::input('local_model_name')),
        ];

        // Secrets are only written when a new value is typed, so an empty field
        // never wipes a working credential.
        foreach (['anthropic_api_key', 'smtp_password', 'ghl_token', 'local_model_key'] as $secret) {
            $new = Request::raw($secret);
            if ($new !== '') {
                $values[$secret] = $new;
            }
        }

        if (Request::bool('clear_ghl_token')) {
            $values['ghl_token'] = '';
        }

        Settings::setMany($values);
        self::flash('success', 'Settings saved.');
    }

    public static function settingsTest(string $what): void
    {
        self::requireLogin();
        if (!Auth::isAdmin()) {
            self::forbidden();
        }
        self::requireCsrf();

        // The test buttons live inside the settings form, so a click carries
        // whatever was just typed. Save it before testing — otherwise pasting a
        // key and pressing Test would check the previous one.
        if (Request::input('run_hour') !== '') {
            self::saveSettings();
        }

        switch ($what) {
            case 'anthropic':
                try {
                    $result = (new Claude(Settings::get('model', 'claude-opus-5')))->testConnection();
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'message' => $e->getMessage()];
                }
                break;

            case 'ghl':
                $client = GoHighLevel::forUser(null);
                $result = $client === null
                    ? ['ok' => false, 'message' => 'Add a token and location ID first.']
                    : $client->testConnection();
                break;

            case 'local':
                $local = LocalModel::configured();
                $result = $local === null
                    ? ['ok' => false, 'message' => 'Add the server address and a model name first.']
                    : $local->test();
                break;

            case 'email':
                $to = Request::input('test_email');
                if ($to === '') {
                    $to = (string) (Auth::user()['email'] ?? '');
                }
                $result = Mailer::sendTest($to);
                break;

            default:
                $result = ['ok' => false, 'message' => 'Unknown test.'];
        }

        self::flash($result['ok'] ? 'success' : 'error', $result['message']);
        self::redirect('/settings');
    }

    // -------------------------------------------------------------- users

    public static function users(): void
    {
        self::requireLogin();
        if (!Auth::isAdmin()) {
            self::forbidden('Only an admin can manage users.');
        }

        View::page('users', [
            'title' => 'Users',
            'users' => Users::all(),
            'loops' => Users::LOOPS,
        ]);
    }

    public static function usersSave(): void
    {
        self::requireLogin();
        if (!Auth::isAdmin()) {
            self::forbidden();
        }
        self::requireCsrf();

        $id = Request::int('id');
        $email = Request::input('email');
        $name = Request::input('name');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::flash('error', 'A name and a valid email address are both required.');
            self::redirect('/users');
        }

        $existing = Users::findByEmail($email);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            self::flash('error', 'Another account already uses ' . $email . '.');
            self::redirect('/users');
        }

        $data = [
            'name' => $name,
            'email' => $email,
            'role' => Request::input('role') === 'admin' ? 'admin' : 'user',
            'loop' => Request::input('loop'),
            'geography' => Request::input('geography'),
            'requires_password' => Request::bool('requires_password') ? 1 : 0,
            'daily_email' => Request::bool('daily_email') ? 1 : 0,
            'autorun' => Request::bool('autorun') ? 1 : 0,
            'active' => Request::bool('active') ? 1 : 0,
            'ghl_location_id' => Request::input('ghl_location_id'),
        ];

        $password = Request::raw('password');
        if ($password !== '') {
            $data['password'] = $password;
        }

        $ghlToken = Request::raw('ghl_token');
        if ($ghlToken !== '' || Request::bool('clear_ghl_token')) {
            $data['ghl_token'] = Request::bool('clear_ghl_token') ? '' : $ghlToken;
        }

        if ($id > 0) {
            // Never let an admin lock themselves out of their own account.
            if ($id === Auth::id()) {
                $data['role'] = 'admin';
                $data['active'] = 1;
            }
            Users::update($id, $data);
            self::flash('success', $name . ' updated.');
        } else {
            if ($password === '' && $data['requires_password'] === 1) {
                self::flash('error', 'Set a password, or turn off "requires password" for email-only sign-in.');
                self::redirect('/users');
            }
            Users::create($data);
            self::flash('success', $name . ' added.');
        }

        self::redirect('/users');
    }

    public static function usersDelete(): void
    {
        self::requireLogin();
        if (!Auth::isAdmin()) {
            self::forbidden();
        }
        self::requireCsrf();

        $id = Request::int('id');

        if ($id === Auth::id()) {
            self::flash('error', 'You cannot delete the account you are signed in with.');
            self::redirect('/users');
        }

        $user = Users::find($id);
        if ($user === null) {
            self::flash('error', 'No such user.');
            self::redirect('/users');
        }

        Users::delete($id);
        self::flash('success', $user['name'] . ' and their leads were removed.');
        self::redirect('/users');
    }

    // ------------------------------------------------------------ helpers

    /**
     * A worker that has stopped checking in usually means the machine is asleep,
     * which otherwise shows up only as leads quietly not arriving.
     */
    private static function workerIsStale(): bool
    {
        if (Settings::engine() !== 'worker') {
            return false;
        }

        $seen = Settings::get('worker_last_seen');
        if ($seen === '') {
            return false;
        }

        $timestamp = strtotime($seen . ' UTC');

        return $timestamp !== false && $timestamp < time() - 36 * 3600;
    }

    /** @return array{ok: bool, message: string} */
    private static function pushLeadToGhl(int $leadId): array
    {
        $lead = Leads::find($leadId);
        if ($lead === null) {
            return ['ok' => false, 'message' => 'Lead not found.'];
        }

        $owner = Users::find((int) $lead['user_id']);
        $client = GoHighLevel::forUser($owner);

        if ($client === null) {
            return ['ok' => false, 'message' => 'GoHighLevel is not connected. Add a token and location ID under Settings.'];
        }

        $result = $client->pushLead($lead);

        if (!$result['ok']) {
            Leads::addActivity($leadId, Auth::id(), 'ghl_error', 'GoHighLevel push failed: ' . $result['message']);

            return ['ok' => false, 'message' => $lead['company'] . ': ' . $result['message']];
        }

        $contactId = (string) ($result['contact_id'] ?? '');
        Leads::markSyncedToGhl($leadId, $contactId);
        Leads::addActivity($leadId, Auth::id(), 'ghl', 'Pushed to GoHighLevel (contact ' . $contactId . ')');

        if (Settings::get('ghl_pipeline_id') !== '' && Settings::get('ghl_stage_id') !== '') {
            $opp = $client->createOpportunity($contactId, $lead);
            if ($opp['ok']) {
                Leads::addActivity($leadId, Auth::id(), 'ghl', 'Opportunity created in GoHighLevel');
            }
        }

        return ['ok' => true, 'message' => $lead['company'] . ' pushed to GoHighLevel.'];
    }

    /** @return array<string, mixed> */
    private static function leadFilters(): array
    {
        // Three states, not two: hiding archived leads is the default, but
        // "only archived" is how you find something to unarchive without
        // wading through the working list.
        $archived = Request::input('archived');
        if (!in_array($archived, ['1', 'only'], true)) {
            $archived = '';
        }

        $filters = [
            'search' => Request::input('q'),
            'status' => Request::input('status'),
            'vertical' => Request::input('vertical'),
            'door' => Request::input('door'),
            'min_score' => Request::input('min_score'),
            'in_ghl' => Request::input('in_ghl'),
            'sort' => Request::input('sort', 'newest'),
            'archived' => $archived,
            'include_archived' => $archived !== '',
            'archived_only' => $archived === 'only',
            'run_id' => Request::int('run_id'),
        ];

        if (Auth::isAdmin()) {
            $owner = Request::int('owner');
            $filters['user_id'] = $owner > 0 ? $owner : null;
        } else {
            $filters['user_id'] = Auth::id();
        }

        return $filters;
    }

    /** Admins see everything; everyone else is scoped to their own leads. */
    private static function scopeUserId(): ?int
    {
        if (!Auth::isAdmin()) {
            return Auth::id();
        }

        $owner = Request::int('owner');

        return $owner > 0 ? $owner : null;
    }

    /** @return list<array<string, mixed>> */
    private static function runnableUsers(): array
    {
        if (Auth::isAdmin()) {
            return array_values(array_filter(
                Users::all(true),
                static fn (array $u): bool => (string) $u['loop'] !== 'none'
            ));
        }

        $user = Auth::user();

        return ($user !== null && (string) $user['loop'] !== 'none') ? [$user] : [];
    }

    public static function requireLogin(): void
    {
        if (!Auth::check()) {
            Auth::start();
            $_SESSION['intended'] = Request::path();
            self::redirect('/login');
        }
    }

    /** Public so the other HTTP controllers can use the one implementation. */
    public static function requireCsrf(): void
    {
        if (!Auth::verifyCsrf(Request::raw('csrf'))) {
            http_response_code(419);
            View::render('error_standalone', [
                'code' => 419,
                'heading' => 'Session expired',
                'message' => 'Reload the page and try that again.',
            ]);
            exit;
        }
    }

    public static function flash(string $type, string $message): void
    {
        Auth::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public static function takeFlash(): array
    {
        Auth::start();
        /** @var list<array{type: string, message: string}> $flash */
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $flash;
    }

    public static function redirect(string $path): never
    {
        $url = str_starts_with($path, 'http') ? $path : View::url(ltrim($path, '/'));
        header('Location: ' . $url);
        exit;
    }

    public static function notFound(string $message = 'Page not found.'): never
    {
        http_response_code(404);
        if (Auth::check()) {
            View::page('error', ['code' => 404, 'heading' => 'Not found', 'message' => $message, 'title' => 'Not found']);
        } else {
            View::render('error_standalone', ['code' => 404, 'heading' => 'Not found', 'message' => $message]);
        }
        exit;
    }

    public static function forbidden(string $message = 'You do not have access to that.'): never
    {
        http_response_code(403);
        View::page('error', ['code' => 403, 'heading' => 'Not allowed', 'message' => $message, 'title' => 'Not allowed']);
        exit;
    }
}
