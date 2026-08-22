<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Database;

/**
 * Storage and sending for outreach emails.
 *
 * Generation lives in Outreach; this owns the rows, the state machine, and the
 * one place that actually puts a message on the wire.
 *
 * The state machine is deliberately small:
 *
 *   draft ──approve──▶ approved ──send──▶ sent
 *     ▲                    │                │
 *     └────edit────────────┘            failed (retryable)
 *
 * Nothing sends from `draft`. Editing an approved email drops it back to draft,
 * because approval means "I read this exact text", and the text just changed.
 */
final class Emails
{
    public const STATUSES = ['draft', 'approved', 'sent', 'failed', 'skipped'];

    /**
     * Save one written email, replacing whatever was at that step.
     *
     * A step that has already been sent is never overwritten — regenerating a
     * cadence must not rewrite history, and a sent email is history.
     */
    public static function put(
        int $leadId,
        int $userId,
        int $step,
        string $subject,
        string $body,
        ?string $model = null
    ): bool {
        $existing = self::forStep($leadId, $step);

        if ($existing !== null && (string) $existing['status'] === 'sent') {
            return false;
        }

        $spec = Outreach::CADENCE[$step] ?? ['day' => 0, 'purpose' => ''];
        $now = Clock::now();

        $fields = [
            'day_offset' => (int) $spec['day'],
            'purpose' => (string) $spec['purpose'],
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            // Rewritten copy has not been read by anyone yet.
            'status' => 'draft',
            'approved_at' => null,
            'error' => null,
            'model' => $model,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            Database::update('emails', $fields, ['id' => (int) $existing['id']]);

            return true;
        }

        Database::insert('emails', $fields + [
            'lead_id' => $leadId,
            'user_id' => $userId,
            'step' => $step,
            'created_at' => $now,
        ]);

        return true;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT e.*, l.company, l.email, l.email_confidence, l.decision_maker, l.ghl_contact_id,
                    l.user_id AS lead_user_id
             FROM emails e JOIN leads l ON l.id = e.lead_id
             WHERE e.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<string, mixed>|null */
    public static function forStep(int $leadId, int $step): ?array
    {
        return Database::first(
            'SELECT * FROM emails WHERE lead_id = :lead AND step = :step',
            ['lead' => $leadId, 'step' => $step]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function forLead(int $leadId): array
    {
        return Database::all('SELECT * FROM emails WHERE lead_id = :lead ORDER BY step', ['lead' => $leadId]);
    }

    /**
     * Cadence state for a set of leads in one query, so a list screen does not
     * run one query per row.
     *
     * @param list<int> $leadIds
     * @return array<int, array{steps: int, drafts: int, approved: int, sent: int, next_due: string|null}>
     */
    public static function summaryFor(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        // Ints straight from the caller's own query, so inlining them is safe
        // and saves building a placeholder list for a variable-length IN.
        $ids = implode(',', array_map('intval', $leadIds));

        $rows = Database::all(
            "SELECT lead_id,
                    COUNT(*) AS steps,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                    MIN(CASE WHEN status = 'approved' THEN due_on END) AS next_due
             FROM emails WHERE lead_id IN ({$ids}) GROUP BY lead_id"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['lead_id']] = [
                'steps' => (int) $row['steps'],
                'drafts' => (int) $row['drafts'],
                'approved' => (int) $row['approved'],
                'sent' => (int) $row['sent'],
                'next_due' => $row['next_due'] !== null ? (string) $row['next_due'] : null,
            ];
        }

        return $out;
    }

    public static function updateText(int $id, string $subject, string $body): void
    {
        Database::update('emails', [
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            // Editing un-approves: approval was of the old text.
            'status' => 'draft',
            'approved_at' => null,
            'error' => null,
            'updated_at' => Clock::now(),
        ], ['id' => $id]);
    }

    /**
     * Approve a step and set the day it is due.
     *
     * Step 1 is due today — approving it is the decision to start. Later steps
     * count from the day the sequence actually started, not from when the copy
     * was written, so a cadence approved a week after it was drafted still
     * spaces itself out properly.
     */
    public static function approve(int $id, ?string $startDate = null): void
    {
        $email = self::find($id);
        if ($email === null || (string) $email['status'] === 'sent') {
            return;
        }

        $start = $startDate ?? Clock::today();
        $due = (int) $email['day_offset'] === 0
            ? $start
            : Clock::addDays($start, (int) $email['day_offset']);

        Database::update('emails', [
            'status' => 'approved',
            'approved_at' => Clock::now(),
            'due_on' => $due,
            'error' => null,
            'updated_at' => Clock::now(),
        ], ['id' => $id]);
    }

    public static function unapprove(int $id): void
    {
        Database::update('emails', [
            'status' => 'draft',
            'approved_at' => null,
            'due_on' => null,
            'updated_at' => Clock::now(),
        ], ['id' => $id]);
    }

    /** Take a step out of the sequence without deleting the copy. */
    public static function skip(int $id): void
    {
        Database::update('emails', [
            'status' => 'skipped',
            'due_on' => null,
            'updated_at' => Clock::now(),
        ], ['id' => $id]);
    }

    public static function delete(int $leadId): void
    {
        // Sent emails stay as the record of what went out.
        Database::run("DELETE FROM emails WHERE lead_id = :lead AND status <> 'sent'", ['lead' => $leadId]);
    }

    /**
     * Approved steps whose day has arrived.
     *
     * @return list<array<string, mixed>>
     */
    public static function due(?string $onOrBefore = null, int $limit = 200): array
    {
        $date = $onOrBefore ?? Clock::today();

        return Database::all(
            "SELECT e.*, l.company, l.email, l.email_confidence, l.ghl_contact_id, l.archived_at
             FROM emails e JOIN leads l ON l.id = e.lead_id
             WHERE e.status = 'approved' AND e.due_on IS NOT NULL AND e.due_on <= :date
             ORDER BY e.due_on, e.lead_id, e.step
             LIMIT {$limit}",
            ['date' => $date]
        );
    }

    /**
     * Send one email through GoHighLevel.
     *
     * GoHighLevel sends to a contact, not to an address, so a lead that has
     * never been pushed has to be pushed first. That is done here rather than
     * left to the caller because every send path needs it and forgetting it
     * produces a confusing "contact not found" from the API.
     *
     * @return array{ok: bool, message: string}
     */
    public static function send(int $id, ?int $actorId = null): array
    {
        $email = self::find($id);

        if ($email === null) {
            return ['ok' => false, 'message' => 'That email no longer exists.'];
        }

        if ((string) $email['status'] === 'sent') {
            return ['ok' => false, 'message' => 'That one has already gone out.'];
        }

        // The gate: nothing sends that a person has not approved.
        if ((string) $email['status'] !== 'approved') {
            return ['ok' => false, 'message' => 'Approve it first — drafts do not send.'];
        }

        $leadId = (int) $email['lead_id'];
        $lead = Leads::find($leadId);

        if ($lead === null) {
            return ['ok' => false, 'message' => 'The lead behind that email is gone.'];
        }

        $deliverable = Outreach::deliverability($lead);
        if (!$deliverable['ok']) {
            self::fail($id, $deliverable['reason']);

            return ['ok' => false, 'message' => $lead['company'] . ': ' . $deliverable['reason']];
        }

        $owner = Users::find((int) $lead['user_id']);
        $client = GoHighLevel::forUser($owner);

        if ($client === null) {
            $reason = 'GoHighLevel is not connected for ' . (string) ($owner['name'] ?? 'that owner') . '.';
            self::fail($id, $reason);

            return ['ok' => false, 'message' => $reason];
        }

        $contactId = trim((string) ($lead['ghl_contact_id'] ?? ''));

        if ($contactId === '') {
            $push = $client->pushLead($lead);
            if (!$push['ok']) {
                self::fail($id, 'Could not create the GoHighLevel contact: ' . $push['message']);

                return ['ok' => false, 'message' => $lead['company'] . ': ' . $push['message']];
            }

            $contactId = (string) ($push['contact_id'] ?? '');
            Leads::markSyncedToGhl($leadId, $contactId);
            Leads::addActivity($leadId, $actorId, 'ghl', 'Pushed to GoHighLevel to send email');
        }

        $result = $client->sendMessage(
            $contactId,
            'Email',
            (string) $email['body'],
            (string) $email['subject']
        );

        if (!$result['ok']) {
            self::fail($id, $result['message']);

            return ['ok' => false, 'message' => $lead['company'] . ': ' . $result['message']];
        }

        Database::update('emails', [
            'status' => 'sent',
            'sent_at' => Clock::now(),
            'error' => null,
            'updated_at' => Clock::now(),
        ], ['id' => $id]);

        Leads::addActivity(
            $leadId,
            $actorId,
            'email',
            'Sent step ' . (int) $email['step'] . ': ' . (string) $email['subject']
        );

        return ['ok' => true, 'message' => $lead['company'] . ': email sent.'];
    }

    /**
     * Send everything that is due.
     *
     * Used by the scheduler and by the button that does the same thing on
     * demand. Archived leads are held rather than sent — archiving a lead is a
     * decision to stop working it, and a queued cadence that keeps emailing
     * afterwards would make that decision meaningless.
     *
     * @return array{sent: int, failed: int, held: int, messages: list<string>}
     */
    public static function sendDue(?int $actorId = null, ?string $onOrBefore = null): array
    {
        $sent = 0;
        $failed = 0;
        $held = 0;
        $messages = [];

        foreach (self::due($onOrBefore) as $row) {
            if ($row['archived_at'] !== null) {
                self::skip((int) $row['id']);
                $held++;
                continue;
            }

            $result = self::send((int) $row['id'], $actorId);

            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
                $messages[] = $result['message'];
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'held' => $held,
            'messages' => array_slice($messages, 0, 5),
        ];
    }

    private static function fail(int $id, string $reason): void
    {
        Database::update('emails', [
            'status' => 'failed',
            'error' => mb_substr($reason, 0, 500),
            'updated_at' => Clock::now(),
        ], ['id' => $id]);
    }

    /**
     * Counts for the Outreach screen header.
     *
     * @return array{drafts: int, approved: int, sent: int, due: int, failed: int}
     */
    public static function counts(?int $userId = null): array
    {
        $where = $userId !== null ? ' WHERE user_id = :uid' : '';
        $params = $userId !== null ? ['uid' => $userId] : [];
        $today = Clock::today();

        $row = Database::first(
            "SELECT
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
             FROM emails{$where}",
            $params
        ) ?? [];

        $dueWhere = $userId !== null ? ' AND user_id = :uid' : '';
        $due = Database::scalar(
            "SELECT COUNT(*) FROM emails
             WHERE status = 'approved' AND due_on IS NOT NULL AND due_on <= :date{$dueWhere}",
            $params + ['date' => $today]
        );

        return [
            'drafts' => (int) ($row['drafts'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'due' => (int) $due,
        ];
    }
}
