<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Database;

/**
 * Rules that put a lead into a GoHighLevel automation on their own.
 *
 * Two ways in, on purpose:
 *
 *   apply()  — driven by something that just happened (a status changed, an
 *              email went out). Immediate, and only ever looks at one lead.
 *   sweep()  — driven by state rather than an event (every new lead, anything
 *              scoring above 85). Re-runnable, and catches leads that arrived
 *              through an import or a batch without anyone watching.
 *
 * The sweep being safe to re-run is what makes the whole thing forgiving: it is
 * called from the scheduler, so a rule added on Tuesday picks up Monday's leads
 * without a migration or a backfill button. The unique index on
 * (lead_id, workflow_id) is what stops that from re-enrolling everybody every
 * half hour.
 */
final class Automations
{
    /**
     * What a rule can fire on. The value column means something different for
     * each, which is why they are described here rather than inferred.
     */
    public const EVENTS = [
        'lead_created' => [
            'label' => 'Every new lead',
            'value' => null,
            'hint' => 'Anyone who lands in this account, however they got here.',
        ],
        'fit_score' => [
            'label' => 'Fit score at least',
            'value' => 'score',
            'hint' => 'Only the strong ones. Set the floor.',
        ],
        'status' => [
            'label' => 'Marked as',
            'value' => 'status',
            'hint' => 'When a disposition is set — booked a meeting, for instance.',
        ],
        'email_sent' => [
            'label' => 'First outreach email sent',
            'value' => null,
            'hint' => 'The moment they have actually been contacted.',
        ],
        'cadence_done' => [
            'label' => 'Cadence finished',
            'value' => null,
            'hint' => 'All six emails have gone and nobody replied. Hand them to a longer nurture.',
        ],
    ];

    public static function eventLabel(string $event): string
    {
        return self::EVENTS[$event]['label'] ?? $event;
    }

    /**
     * Describe a rule in one line, for a list.
     *
     * @param array<string, mixed> $rule
     */
    public static function describe(array $rule): string
    {
        $event = (string) $rule['on_event'];
        $value = trim((string) ($rule['event_value'] ?? ''));
        $label = self::eventLabel($event);

        return match ($event) {
            'fit_score' => $label . ' ' . ($value === '' ? '0' : $value),
            'status' => $label . ' "' . Leads::statusLabel($value) . '"',
            default => $label,
        };
    }

    // ------------------------------------------------------------- the rules

    /** @return list<array<string, mixed>> */
    public static function rules(?int $userId = null, bool $activeOnly = false): array
    {
        $where = [];
        $params = [];

        if ($userId !== null) {
            $where[] = 'r.user_id = :uid';
            $params['uid'] = $userId;
        }
        if ($activeOnly) {
            $where[] = 'r.active = 1';
        }

        $sql = 'SELECT r.*, u.name AS owner_name FROM automation_rules r
                JOIN users u ON u.id = r.user_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return Database::all($sql . ' ORDER BY u.name, r.on_event', $params);
    }

    /** @return array<string, mixed>|null */
    public static function rule(int $id): ?array
    {
        return Database::first('SELECT * FROM automation_rules WHERE id = :id', ['id' => $id]);
    }

    public static function addRule(
        int $userId,
        string $workflowId,
        string $workflowName,
        string $event,
        string $value = ''
    ): int {
        if (!array_key_exists($event, self::EVENTS) || trim($workflowId) === '') {
            return 0;
        }

        $now = Clock::now();

        return Database::insert('automation_rules', [
            'user_id' => $userId,
            'workflow_id' => trim($workflowId),
            'workflow_name' => mb_substr($workflowName, 0, 190),
            'on_event' => $event,
            'event_value' => self::normaliseValue($event, $value),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function setRuleActive(int $id, bool $active): void
    {
        Database::update(
            'automation_rules',
            ['active' => $active ? 1 : 0, 'updated_at' => Clock::now()],
            ['id' => $id]
        );
    }

    public static function deleteRule(int $id): void
    {
        Database::run('DELETE FROM automation_rules WHERE id = :id', ['id' => $id]);
    }

    private static function normaliseValue(string $event, string $value): ?string
    {
        return match ($event) {
            'fit_score' => (string) max(0, min(100, (int) $value)),
            'status' => array_key_exists($value, Leads::STATUSES) ? $value : 'new',
            default => null,
        };
    }

    // -------------------------------------------------------- what is in what

    /** @return list<array<string, mixed>> */
    public static function enrolmentsFor(int $leadId): array
    {
        return Database::all(
            'SELECT * FROM enrolments WHERE lead_id = :id AND removed_at IS NULL ORDER BY created_at DESC',
            ['id' => $leadId]
        );
    }

    public static function isEnrolled(int $leadId, string $workflowId): bool
    {
        return Database::first(
            'SELECT id FROM enrolments WHERE lead_id = :id AND workflow_id = :wf AND removed_at IS NULL',
            ['id' => $leadId, 'wf' => $workflowId]
        ) !== null;
    }

    /**
     * Put a lead into an automation.
     *
     * The contact is created in GoHighLevel first if it does not exist, for the
     * same reason sending an email does it: a workflow enrols a contact, not an
     * address, and forgetting that produces a "contact not found" nobody can act
     * on.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, message: string}
     */
    public static function enrol(
        array $lead,
        string $workflowId,
        string $workflowName = '',
        string $source = 'manual',
        ?int $ruleId = null,
        ?int $actorId = null
    ): array {
        $leadId = (int) $lead['id'];

        if (trim($workflowId) === '') {
            return ['ok' => false, 'message' => 'Pick an automation first.'];
        }

        if (self::isEnrolled($leadId, $workflowId)) {
            return ['ok' => false, 'message' => $lead['company'] . ' is already in that automation.'];
        }

        $owner = Users::find((int) $lead['user_id']);
        $client = GoHighLevel::forUser($owner);

        if ($client === null) {
            return [
                'ok' => false,
                'message' => 'GoHighLevel is not connected for ' . (string) ($owner['name'] ?? 'that owner') . '.',
            ];
        }

        $contactId = trim((string) ($lead['ghl_contact_id'] ?? ''));

        if ($contactId === '') {
            $push = $client->pushLead($lead);
            if (!$push['ok']) {
                return ['ok' => false, 'message' => $lead['company'] . ': ' . $push['message']];
            }

            $contactId = (string) ($push['contact_id'] ?? '');
            Leads::markSyncedToGhl($leadId, $contactId);
        }

        $result = $client->enrollInWorkflow($contactId, $workflowId);

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $lead['company'] . ': ' . $result['message']];
        }

        self::record($leadId, $workflowId, $workflowName, $source, $ruleId);

        Leads::addActivity(
            $leadId,
            $actorId,
            'automation',
            'Added to automation "' . ($workflowName !== '' ? $workflowName : $workflowId) . '"'
            . ($source === 'rule' ? ' by a rule' : '')
        );

        return ['ok' => true, 'message' => $lead['company'] . ' added to the automation.'];
    }

    /**
     * @param array<string, mixed> $lead
     * @return array{ok: bool, message: string}
     */
    public static function remove(array $lead, string $workflowId, ?int $actorId = null): array
    {
        $leadId = (int) $lead['id'];
        $contactId = trim((string) ($lead['ghl_contact_id'] ?? ''));

        if ($contactId === '') {
            return ['ok' => false, 'message' => $lead['company'] . ' is not in GoHighLevel.'];
        }

        $client = GoHighLevel::forUser(Users::find((int) $lead['user_id']));
        if ($client === null) {
            return ['ok' => false, 'message' => 'GoHighLevel is not connected for that owner.'];
        }

        $result = $client->removeFromWorkflow($contactId, $workflowId);

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $lead['company'] . ': ' . $result['message']];
        }

        // Marked rather than deleted, so a rule does not immediately re-add
        // somebody who was deliberately taken out.
        Database::update(
            'enrolments',
            ['removed_at' => Clock::now()],
            ['lead_id' => $leadId, 'workflow_id' => $workflowId]
        );

        Leads::addActivity($leadId, $actorId, 'automation', 'Removed from an automation');

        return ['ok' => true, 'message' => $lead['company'] . ' removed from the automation.'];
    }

    private static function record(
        int $leadId,
        string $workflowId,
        string $workflowName,
        string $source,
        ?int $ruleId
    ): void {
        $existing = Database::first(
            'SELECT id FROM enrolments WHERE lead_id = :id AND workflow_id = :wf',
            ['id' => $leadId, 'wf' => $workflowId]
        );

        if ($existing !== null) {
            // Re-enrolling someone previously removed: clear the removal rather
            // than tripping the unique index.
            Database::update('enrolments', [
                'removed_at' => null,
                'source' => $source,
                'rule_id' => $ruleId,
                'created_at' => Clock::now(),
            ], ['id' => (int) $existing['id']]);

            return;
        }

        Database::insert('enrolments', [
            'lead_id' => $leadId,
            'workflow_id' => $workflowId,
            'workflow_name' => mb_substr($workflowName, 0, 190),
            'source' => $source,
            'rule_id' => $ruleId,
            'created_at' => Clock::now(),
        ]);
    }

    // ------------------------------------------------------------ the engine

    /**
     * Something happened to one lead. Enrol it wherever a rule says so.
     *
     * Failures are swallowed into the activity trail rather than thrown: this is
     * called from the middle of saving a disposition and from the middle of
     * sending an email, and a GoHighLevel outage must not take either of those
     * down with it.
     *
     * @param array<string, mixed> $lead
     * @return int how many automations it was added to
     */
    public static function apply(string $event, array $lead, ?int $actorId = null): int
    {
        if (!array_key_exists($event, self::EVENTS)) {
            return 0;
        }

        if (($lead['archived_at'] ?? null) !== null) {
            return 0;
        }

        $added = 0;

        foreach (self::rules((int) $lead['user_id'], true) as $rule) {
            if ((string) $rule['on_event'] !== $event || !self::matches($rule, $lead)) {
                continue;
            }

            $result = self::enrol(
                $lead,
                (string) $rule['workflow_id'],
                (string) ($rule['workflow_name'] ?? ''),
                'rule',
                (int) $rule['id'],
                $actorId
            );

            if ($result['ok']) {
                $added++;
                Database::run(
                    'UPDATE automation_rules SET enrolled_count = enrolled_count + 1, last_run_at = :now
                     WHERE id = :id',
                    ['now' => Clock::now(), 'id' => (int) $rule['id']]
                );
            } elseif (!str_contains($result['message'], 'already in that automation')) {
                Leads::addActivity(
                    (int) $lead['id'],
                    null,
                    'automation_error',
                    'Automation rule failed: ' . $result['message']
                );
            }
        }

        return $added;
    }

    /**
     * Catch up the state-based rules across every lead that qualifies.
     *
     * Bounded per pass so a first run against a large account cannot sit there
     * making hundreds of API calls inside one cron tick. What it misses this
     * time it picks up next time.
     *
     * @return array{enrolled: int, failed: int, considered: int}
     */
    public static function sweep(int $limit = 50): array
    {
        $enrolled = 0;
        $failed = 0;
        $considered = 0;

        foreach (self::rules(null, true) as $rule) {
            $event = (string) $rule['on_event'];

            // Only the state-shaped rules sweep. The event-shaped ones already
            // fired at the moment they described, and re-deciding them from
            // state later would enrol people the event never happened for.
            if (!in_array($event, ['lead_created', 'fit_score'], true)) {
                continue;
            }

            $minScore = $event === 'fit_score' ? (int) ($rule['event_value'] ?? 0) : 0;

            $candidates = Database::all(
                "SELECT l.* FROM leads l
                 LEFT JOIN enrolments e
                        ON e.lead_id = l.id AND e.workflow_id = :wf
                 WHERE l.user_id = :uid
                   AND l.archived_at IS NULL
                   AND l.fit_score >= :score
                   AND e.id IS NULL
                 ORDER BY l.id DESC
                 LIMIT {$limit}",
                ['wf' => (string) $rule['workflow_id'], 'uid' => (int) $rule['user_id'], 'score' => $minScore]
            );

            foreach ($candidates as $lead) {
                $considered++;

                $result = self::enrol(
                    $lead,
                    (string) $rule['workflow_id'],
                    (string) ($rule['workflow_name'] ?? ''),
                    'rule',
                    (int) $rule['id']
                );

                if ($result['ok']) {
                    $enrolled++;
                } else {
                    $failed++;
                    // Stop hammering a broken connection: one failure here means
                    // the next fifty will fail the same way.
                    if (str_contains($result['message'], 'not connected')) {
                        break;
                    }
                }
            }

            if ($enrolled > 0) {
                Database::run(
                    'UPDATE automation_rules SET enrolled_count = enrolled_count + :n, last_run_at = :now
                     WHERE id = :id',
                    ['n' => $enrolled, 'now' => Clock::now(), 'id' => (int) $rule['id']]
                );
            }
        }

        return ['enrolled' => $enrolled, 'failed' => $failed, 'considered' => $considered];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $lead
     */
    private static function matches(array $rule, array $lead): bool
    {
        $value = (string) ($rule['event_value'] ?? '');

        return match ((string) $rule['on_event']) {
            'fit_score' => (int) $lead['fit_score'] >= (int) $value,
            'status' => (string) $lead['status'] === $value,
            default => true,
        };
    }
}
