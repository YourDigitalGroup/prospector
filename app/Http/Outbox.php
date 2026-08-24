<?php

declare(strict_types=1);

namespace Prospector\Http;

use Prospector\Auth;
use Prospector\Emails;
use Prospector\Leads;
use Prospector\Outreach;
use Prospector\Support\Background;
use Prospector\Support\Clock;
use Prospector\Support\Request;
use Prospector\Support\Settings;
use Prospector\Support\View;
use Prospector\Users;

/**
 * The Outreach screens: build cadences across a set of leads, review the copy,
 * approve it, and send.
 *
 * Two things are load-bearing here and worth stating up front.
 *
 * Nothing sends without an explicit approval of the exact text, and editing an
 * approved email un-approves it. That is what makes "approve and send" on a
 * hundred leads a defensible action rather than a leap of faith.
 *
 * A build is resumable rather than transactional. It writes each lead's cadence
 * as it finishes that lead, so a build that dies halfway leaves real work
 * behind, and pressing Build again picks up only the leads that still have
 * nothing. The emails table is its own progress bar.
 */
final class Outbox
{
    private const BUILD_KEY = 'outreach_build';

    /** A build marker older than this is treated as dead rather than running. */
    private const BUILD_STALE_SECONDS = 900;

    /** How many leads one build pass will write copy for. */
    private const BUILD_CAP = 40;

    public static function index(): void
    {
        Controller::requireLogin();

        $scope = self::scopeUserId();
        $filters = [
            'user_id' => $scope,
            'status' => Request::input('status'),
            'search' => Request::input('q'),
            'sort' => 'newest',
        ];

        $stage = Request::input('stage');
        $leads = Leads::search($filters, 200);

        $ids = array_map(static fn (array $l): int => (int) $l['id'], $leads);
        $summary = Emails::summaryFor($ids);

        // Decorate in PHP rather than in SQL: the per-lead judgement about
        // whether a lead is sendable at all lives in Outreach, and duplicating
        // it in a query is how the two drift apart.
        $rows = [];
        foreach ($leads as $lead) {
            $id = (int) $lead['id'];
            $cadence = $summary[$id] ?? ['steps' => 0, 'drafts' => 0, 'approved' => 0, 'sent' => 0, 'next_due' => null];
            $deliverable = Outreach::deliverability($lead);

            $row = [
                'lead' => $lead,
                'cadence' => $cadence,
                'sendable' => $deliverable['ok'],
                'blocked_reason' => $deliverable['reason'],
                'unverified' => Outreach::isUnverified($lead),
                'stage' => self::stageOf($cadence, $deliverable['ok']),
            ];

            if ($stage === '' || $stage === null || $stage === $row['stage']) {
                $rows[] = $row;
            }
        }

        View::page('outreach', [
            'title' => 'Outreach',
            'rows' => $rows,
            'counts' => Emails::counts($scope),
            'owners' => Auth::isAdmin() ? Users::all() : [],
            'scopeUserId' => $scope,
            'stage' => (string) ($stage ?? ''),
            'search' => (string) Request::input('q'),
            'statuses' => Leads::STATUSES,
            'statusFilter' => (string) Request::input('status'),
            'build' => self::buildState(),
            'cadence' => Outreach::CADENCE,
            'model' => Outreach::model(),
            'today' => Clock::today(),
        ]);
    }

    /**
     * One lead's full cadence, editable.
     */
    public static function lead(): void
    {
        Controller::requireLogin();

        $lead = Leads::find(Request::int('id'));
        if ($lead === null) {
            Controller::notFound('That lead does not exist.');
        }
        if (!Auth::canAccessUser((int) $lead['user_id'])) {
            Controller::forbidden();
        }

        View::page('outreach_lead', [
            'title' => 'Outreach — ' . (string) $lead['company'],
            'lead' => $lead,
            'emails' => Emails::forLead((int) $lead['id']),
            'cadence' => Outreach::CADENCE,
            'deliverable' => Outreach::deliverability($lead),
            'unverified' => Outreach::isUnverified($lead),
            'today' => Clock::today(),
        ]);
    }

    /**
     * Write copy for a set of leads.
     *
     * Kicked into the background because it is one API call per lead and a
     * browser will not wait for forty of them. The response goes out first, the
     * screen polls, and the rows appear as they are written.
     */
    public static function build(): void
    {
        Controller::requireLogin();
        Controller::requireCsrf();

        $steps = Request::input('steps') === 'first' ? [1] : array_keys(Outreach::CADENCE);
        $onlyMissing = Request::input('only_missing') !== '0';
        $ids = Request::ints('ids');

        $targets = self::buildTargets($ids, $onlyMissing);

        if ($targets === []) {
            Controller::flash('error', $onlyMissing
                ? 'Nothing to build — every lead you picked already has a cadence.'
                : 'Nothing to build — pick at least one lead with an email address.');
            Controller::redirect(self::back());
        }

        $capped = count($targets) > self::BUILD_CAP;
        $targets = array_slice($targets, 0, self::BUILD_CAP);

        Settings::set(self::BUILD_KEY, (string) json_encode([
            'started_at' => time(),
            'total' => count($targets),
            'steps' => count($steps),
            'actor' => Auth::id(),
        ]));

        $message = 'Writing ' . count($steps) . ' ' . (count($steps) === 1 ? 'email' : 'emails')
            . ' for ' . count($targets) . ' ' . (count($targets) === 1 ? 'lead' : 'leads')
            . '. They will appear here as they are written.';

        if ($capped) {
            $message .= ' Capped at ' . self::BUILD_CAP . ' this pass — run it again for the rest.';
        }

        Controller::flash('success', $message);

        $back = View::url(ltrim(self::back(), '/'));

        if (!Background::canDetach()) {
            // No FastCGI to hand the response to, so do the work inline and
            // hope the host is patient. Still redirects, so a refresh cannot
            // re-post the build.
            Background::extendLimits(1800);
            self::writeFor($targets, $steps);
            Controller::redirect(self::back());
        }

        Background::respondThenContinue('', 303, ['Location' => $back]);

        // The session lock would otherwise block every poll for the whole
        // build, which would look exactly like the hang this avoids.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Background::extendLimits(1800);
        self::writeFor($targets, $steps);
        exit;
    }

    /**
     * Approve every draft on the selected leads.
     *
     * @return never
     */
    public static function approve(): void
    {
        Controller::requireLogin();
        Controller::requireCsrf();

        $approved = 0;
        $start = Clock::today();

        foreach (self::selectedLeads() as $lead) {
            foreach (Emails::forLead((int) $lead['id']) as $email) {
                if ((string) $email['status'] === 'draft') {
                    Emails::approve((int) $email['id'], $start);
                    $approved++;
                }
            }
        }

        Controller::flash(
            $approved > 0 ? 'success' : 'error',
            $approved > 0
                ? $approved . ' ' . ($approved === 1 ? 'email' : 'emails') . ' approved and scheduled.'
                : 'Nothing to approve — those leads have no drafts.'
        );

        Controller::redirect(self::back());
    }

    /**
     * Send what is due across the selected leads, or across everything.
     *
     * Unverified `pattern` addresses are held back unless the sender explicitly
     * includes them. One deliberate send to an inferred address is a judgement
     * call; a hundred at once is how a sending domain gets burned.
     */
    public static function send(): void
    {
        Controller::requireLogin();
        Controller::requireCsrf();

        if (Request::input('confirm') !== '1') {
            Controller::flash('error', 'Nothing was sent — the confirmation step was not completed.');
            Controller::redirect(self::back());
        }

        $includeUnverified = Request::input('include_unverified') === '1';
        $ids = Request::ints('ids');
        $limitToSelected = $ids !== [];
        $allowed = array_flip($ids);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $withheld = 0;
        $messages = [];

        foreach (Emails::due() as $row) {
            $leadId = (int) $row['lead_id'];

            if ($limitToSelected && !isset($allowed[$leadId])) {
                continue;
            }

            $lead = Leads::find($leadId);
            if ($lead === null || !Auth::canAccessUser((int) $lead['user_id'])) {
                $skipped++;
                continue;
            }

            if ($lead['archived_at'] !== null) {
                Emails::skip((int) $row['id']);
                $skipped++;
                continue;
            }

            if (!$includeUnverified && Outreach::isUnverified($lead)) {
                $withheld++;
                continue;
            }

            $result = Emails::send((int) $row['id'], Auth::id());

            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
                $messages[$result['message']] = true;
            }
        }

        $summary = $sent . ' ' . ($sent === 1 ? 'email' : 'emails') . ' sent';
        if ($withheld > 0) {
            // Say where the override is. Reporting a hold without saying how to
            // release it just reads as a silent failure.
            $summary .= ', ' . $withheld . ' held back as unverified '
                . ($withheld === 1 ? 'address' : 'addresses')
                . ' — tick the leads and use "Send what is due" with '
                . '"Include unverified addresses" to send those';
        }
        if ($skipped > 0) {
            $summary .= ', ' . $skipped . ' skipped';
        }
        if ($failed > 0) {
            $summary .= ', ' . $failed . ' failed';
            if ($messages !== []) {
                $summary .= ' (' . implode('; ', array_slice(array_keys($messages), 0, 2)) . ')';
            }
        }

        Controller::flash($sent > 0 ? 'success' : 'error', $summary . '.');
        Controller::redirect(self::back());
    }

    /**
     * One action on one email: save, approve, unapprove, skip, send, or discard
     * the lead's whole unsent cadence.
     */
    public static function step(): void
    {
        Controller::requireLogin();
        Controller::requireCsrf();

        $action = Request::input('action');

        // 'discard' works on a lead rather than on one email.
        if ($action === 'discard') {
            $lead = Leads::find(Request::int('lead_id'));
            if ($lead === null || !Auth::canAccessUser((int) $lead['user_id'])) {
                Controller::forbidden();
            }

            Emails::delete((int) $lead['id']);
            Controller::flash('success', 'Cleared the unsent emails for ' . (string) $lead['company'] . '.');
            Controller::redirect(self::back());
        }

        $email = Emails::find(Request::int('id'));
        if ($email === null) {
            Controller::notFound('That email no longer exists.');
        }
        if (!Auth::canAccessUser((int) $email['lead_user_id'])) {
            Controller::forbidden();
        }

        $id = (int) $email['id'];

        match ($action) {
            'save' => self::save($id),
            'approve' => self::approveOne($id),
            'unapprove' => self::unapproveOne($id),
            'skip' => self::skipOne($id),
            'send' => self::sendOne($id, $email),
            default => Controller::flash('error', 'Unknown action.'),
        };

        Controller::redirect(self::back());
    }

    private static function save(int $id): void
    {
        $subject = trim(Request::input('subject'));
        $body = trim(Request::raw('body'));

        if ($subject === '' || $body === '') {
            Controller::flash('error', 'An email needs both a subject and a body.');

            return;
        }

        Emails::updateText($id, $subject, $body);
        Controller::flash('success', 'Saved. It needs approving again before it can send.');
    }

    private static function approveOne(int $id): void
    {
        Emails::approve($id);
        Controller::flash('success', 'Approved.');
    }

    private static function unapproveOne(int $id): void
    {
        Emails::unapprove($id);
        Controller::flash('success', 'Back to draft — it will not send.');
    }

    private static function skipOne(int $id): void
    {
        Emails::skip($id);
        Controller::flash('success', 'Skipped. The rest of the sequence still runs.');
    }

    /** @param array<string, mixed> $email */
    private static function sendOne(int $id, array $email): void
    {
        // Sending one email on purpose is allowed to an unverified address —
        // the sender is looking right at it. The mass send is the one that has
        // to be careful.
        if ((string) $email['status'] === 'draft') {
            Emails::approve($id);
        }

        $result = Emails::send($id, Auth::id());
        Controller::flash($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Write copy for these leads, one API call each.
     *
     * Each lead is committed as it finishes. A failure is recorded against the
     * lead's activity trail rather than thrown, so one bad lead does not take
     * the rest of the build down with it.
     *
     * @param list<array<string, mixed>> $leads
     * @param list<int>                  $steps
     */
    private static function writeFor(array $leads, array $steps): void
    {
        $owners = [];
        $written = 0;
        $failures = 0;
        $spend = 0.0;

        foreach ($leads as $lead) {
            $ownerId = (int) $lead['user_id'];
            $owners[$ownerId] ??= Users::find($ownerId);
            $owner = $owners[$ownerId];

            if ($owner === null) {
                continue;
            }

            $result = Outreach::write($lead, $owner, $steps);

            if (!$result['ok']) {
                $failures++;
                Leads::addActivity(
                    (int) $lead['id'],
                    null,
                    'email_error',
                    'Could not write outreach copy: ' . $result['message']
                );
                continue;
            }

            foreach ($result['emails'] as $written_email) {
                Emails::put(
                    (int) $lead['id'],
                    $ownerId,
                    $written_email['step'],
                    $written_email['subject'],
                    $written_email['body'],
                    $result['cost']['model'] ?? null
                );
            }

            $written++;
            $spend += (float) ($result['cost']['dollars'] ?? 0);

            Leads::addActivity(
                (int) $lead['id'],
                null,
                'email_draft',
                'Wrote ' . count($result['emails']) . ' outreach '
                . (count($result['emails']) === 1 ? 'email' : 'emails')
                . ' (' . ($result['cost']['model'] ?? 'unknown model') . ')'
            );
        }

        Settings::forget(self::BUILD_KEY);

        Background::log(sprintf(
            'Outreach build finished: %d written, %d failed, about $%.2f.',
            $written,
            $failures,
            $spend
        ));
    }

    /**
     * Which leads a build should write for.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function buildTargets(array $ids, bool $onlyMissing): array
    {
        $targets = [];

        if ($ids !== []) {
            foreach ($ids as $id) {
                $lead = Leads::find($id);
                if ($lead !== null && Auth::canAccessUser((int) $lead['user_id'])) {
                    $targets[] = $lead;
                }
            }
        } else {
            // No selection means "everything on this screen that needs it",
            // which is the whole point of a mass build.
            $targets = Leads::search([
                'user_id' => self::scopeUserId(),
                'status' => Request::input('status'),
                'search' => Request::input('q'),
                'sort' => 'newest',
            ], 200);
        }

        return array_values(array_filter($targets, static function (array $lead) use ($onlyMissing): bool {
            // No address means no email, and writing copy nobody can send is
            // just spending money to make a screen look busier.
            if (!Outreach::deliverability($lead)['ok']) {
                return false;
            }

            return !$onlyMissing || Emails::forLead((int) $lead['id']) === [];
        }));
    }

    /** @return list<array<string, mixed>> */
    private static function selectedLeads(): array
    {
        $leads = [];

        foreach (Request::ints('ids') as $id) {
            $lead = Leads::find($id);
            if ($lead !== null && Auth::canAccessUser((int) $lead['user_id'])) {
                $leads[] = $lead;
            }
        }

        return $leads;
    }

    /**
     * Where a lead sits in the funnel, for the stage filter.
     *
     * @param array{steps: int, drafts: int, approved: int, sent: int, next_due: string|null} $cadence
     */
    private static function stageOf(array $cadence, bool $sendable): string
    {
        if (!$sendable) {
            return 'blocked';
        }
        if ($cadence['steps'] === 0) {
            return 'none';
        }
        if ($cadence['sent'] > 0) {
            return 'sending';
        }
        if ($cadence['drafts'] > 0) {
            return 'drafts';
        }

        return 'approved';
    }

    /**
     * @return array{running: bool, total: int, done: int, steps: int}|null
     */
    private static function buildState(): ?array
    {
        $raw = Settings::get(self::BUILD_KEY, '');
        if ($raw === '') {
            return null;
        }

        $state = json_decode($raw, true);
        if (!is_array($state)) {
            return null;
        }

        $startedAt = (int) ($state['started_at'] ?? 0);

        // A marker left behind by a process that died would otherwise spin the
        // screen forever.
        if ($startedAt < time() - self::BUILD_STALE_SECONDS) {
            Settings::forget(self::BUILD_KEY);

            return null;
        }

        return [
            'running' => true,
            'total' => (int) ($state['total'] ?? 0),
            'done' => 0,
            'steps' => (int) ($state['steps'] ?? 0),
        ];
    }

    private static function scopeUserId(): ?int
    {
        if (!Auth::isAdmin()) {
            return Auth::id();
        }

        $owner = Request::int('owner');

        return $owner > 0 ? $owner : null;
    }

    /** Where to go back to after an action, kept inside the app. */
    private static function back(): string
    {
        $return = Request::input('return');

        return str_starts_with($return, '/') ? $return : '/outreach';
    }
}
