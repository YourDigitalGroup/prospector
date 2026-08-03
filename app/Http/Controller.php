<?php

declare(strict_types=1);

namespace Prospector\Http;

use Prospector\Auth;
use Prospector\Claude;
use Prospector\GoHighLevel;
use Prospector\Leads;
use Prospector\Mailer;
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
            'ghlReady' => GoHighLevel::forUser(Auth::user()) !== null,
        ]);
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

        View::page('lead', [
            'title' => (string) $lead['company'],
            'lead' => $lead,
            'activities' => Leads::activities($id),
            'owners' => Auth::isAdmin() ? Users::all() : [],
            'ghlReady' => GoHighLevel::forUser(Users::find((int) $lead['user_id'])) !== null,
            'run' => $lead['run_id'] !== null ? Runs::find((int) $lead['run_id']) : null,
        ]);
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

            default:
                self::flash('error', 'Unknown action.');
        }

        self::redirect($back);
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
        $failed = 0;
        $messages = [];

        foreach ($ids as $id) {
            $lead = Leads::find($id);
            if ($lead === null || !Auth::canAccessUser((int) $lead['user_id'])) {
                $failed++;
                continue;
            }

            if ($action === 'archive') {
                Leads::archive($id, Auth::id());
                $done++;
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

        $summary = $done . ' ' . ($done === 1 ? 'lead' : 'leads') . ' updated';
        if ($failed > 0) {
            $summary .= ', ' . $failed . ' skipped';
            if ($messages !== []) {
                $summary .= ' (' . implode('; ', array_slice(array_keys($messages), 0, 2)) . ')';
            }
        }

        self::flash($done > 0 ? 'success' : 'error', $summary . '.');
        self::redirect($back);
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
        ];

        // Secrets are only written when a new value is typed, so an empty field
        // never wipes a working credential.
        foreach (['anthropic_api_key', 'smtp_password', 'ghl_token'] as $secret) {
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
        $filters = [
            'search' => Request::input('q'),
            'status' => Request::input('status'),
            'vertical' => Request::input('vertical'),
            'door' => Request::input('door'),
            'min_score' => Request::input('min_score'),
            'in_ghl' => Request::input('in_ghl'),
            'sort' => Request::input('sort', 'newest'),
            'include_archived' => Request::bool('archived'),
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

    private static function requireCsrf(): void
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
