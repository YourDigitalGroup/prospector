<?php

declare(strict_types=1);

namespace Prospector;

use DateTimeImmutable;
use DateTimeZone;
use Prospector\Support\Clock;
use Prospector\Support\Database;
use Prospector\Support\Settings;

/**
 * The numbers and lists behind Home: what to do next, and how the week is going.
 *
 * Everything is scoped by lead owner — a seller sees their own leads and the
 * follow-ups they set; an admin sees the team, or one seller when filtered to
 * them. Built entirely from what people log: actions, statuses, reminders.
 */
final class Dashboard
{
    /** Actions that ask for a reply — if nothing comes back, it needs chasing. */
    private const OUTREACH = ['called', 'voicemail', 'emailed', 'texted', 'linkedin', 'proposal'];

    /** Dispositions that end the chase; nothing in them belongs in the queue. */
    private const CLOSED = ['signed', 'not_interested', 'disqualified'];

    /** Reached out this long ago and heard nothing — time to try again. */
    public const STALLED_AFTER_DAYS = 2;

    /** Nothing at all for this long — the lead is going cold. */
    public const COLD_AFTER_DAYS = 10;

    private const SECTION_LIMIT = 40;

    /**
     * The queue: one list of who to contact next, in four sections.
     *
     * A lead appears once, in the most urgent section it qualifies for. Anything
     * with an open reminder is handled by the reminder — due now, or showing on
     * the upcoming strip — so it is kept out of the other three, which would
     * otherwise nag about a follow-up that is already scheduled.
     *
     * @return array{due: list<array<string, mixed>>, stalled: list<array<string, mixed>>, cold: list<array<string, mixed>>, fresh: list<array<string, mixed>>}
     */
    public static function queue(?int $userId): array
    {
        $now = Clock::now();
        $stalledBefore = self::daysAgo(self::STALLED_AFTER_DAYS);
        $coldBefore = self::daysAgo(self::COLD_AFTER_DAYS);

        $scope = $userId !== null ? ' AND l.user_id = :uid' : '';
        $params = $userId !== null ? ['uid' => $userId] : [];
        $noReminder = 'NOT EXISTS (SELECT 1 FROM reminders r WHERE r.lead_id = l.id AND r.done_at IS NULL)';
        $closed = self::inList(self::CLOSED);
        $outreach = self::inList(self::OUTREACH);
        $limit = self::SECTION_LIMIT;

        // Follow-ups that have come due, oldest first. A seller's are the ones
        // they set; the team view shows everybody's.
        $due = Database::all(
            "SELECT l.*, u.name AS owner_name, r.id AS reminder_id, r.due_at AS reminder_due, r.note AS reminder_note
             FROM reminders r
             JOIN leads l ON l.id = r.lead_id
             JOIN users u ON u.id = l.user_id
             WHERE r.done_at IS NULL AND r.due_at <= :now AND l.archived_at IS NULL"
                . ($userId !== null ? ' AND r.user_id = :uid' : '') . "
             ORDER BY r.due_at ASC
             LIMIT {$limit}",
            $params + ['now' => $now]
        );

        // Reached out, heard nothing, no follow-up set. Longest-waiting first.
        $stalled = Database::all(
            "SELECT l.*, u.name AS owner_name FROM leads l JOIN users u ON u.id = l.user_id
             WHERE l.archived_at IS NULL{$scope}
               AND l.status NOT IN ({$closed}) AND l.status <> 'meeting'
               AND l.last_action IN ({$outreach})
               AND l.last_action_at <= :stalled AND l.last_action_at > :cold
               AND {$noReminder}
             ORDER BY l.last_action_at ASC, l.fit_score DESC
             LIMIT {$limit}",
            $params + ['stalled' => $stalledBefore, 'cold' => $coldBefore]
        );

        // In play, but nothing for ten days. Includes a booked meeting nobody
        // has followed up — the costliest one to let slide.
        $cold = Database::all(
            "SELECT l.*, u.name AS owner_name FROM leads l JOIN users u ON u.id = l.user_id
             WHERE l.archived_at IS NULL{$scope}
               AND l.status NOT IN ({$closed})
               AND (l.status <> 'new' OR l.last_action IS NOT NULL)
               AND COALESCE(l.last_action_at, l.updated_at) <= :cold
               AND {$noReminder}
             ORDER BY l.fit_score DESC, COALESCE(l.last_action_at, l.updated_at) ASC
             LIMIT {$limit}",
            $params + ['cold' => $coldBefore]
        );

        // Never touched. Best fit first, and only a handful — the full list is
        // what the Leads screen is for. A lead that has ever had a follow-up
        // set is not untouched, even if nothing was logged against it: someone
        // has been working it, and seeing it reappear as "fresh" the moment
        // its follow-up is ticked off would say otherwise.
        $fresh = Database::all(
            "SELECT l.*, u.name AS owner_name FROM leads l JOIN users u ON u.id = l.user_id
             WHERE l.archived_at IS NULL{$scope}
               AND l.status = 'new' AND l.last_action IS NULL
               AND NOT EXISTS (SELECT 1 FROM reminders r WHERE r.lead_id = l.id)
             ORDER BY l.fit_score DESC, l.created_at DESC
             LIMIT 8",
            $params
        );

        return ['due' => $due, 'stalled' => $stalled, 'cold' => $cold, 'fresh' => $fresh];
    }

    /**
     * This week against last week: calls, emails, meetings booked, wins, and
     * every touch together against the weekly goal.
     *
     * Weeks run Monday to Sunday in business time.
     *
     * @return array{this: array<string, int>, last: array<string, int>, days: list<array{label: string, date: string, count: int, today: bool}>, goal: int}
     */
    public static function scoreboard(?int $userId): array
    {
        $monday = Clock::local()->setTime(0, 0)->modify('monday this week');
        $lastMonday = $monday->modify('-7 days');
        $nextMonday = $monday->modify('+7 days');

        $current = self::tally($userId, $monday, $nextMonday);
        $last = self::tally($userId, $lastMonday, $monday);

        // Touches per day this week, for the little bar chart.
        $rows = Database::all(
            'SELECT a.created_at FROM activities a JOIN leads l ON l.id = a.lead_id
             WHERE a.created_at >= :from AND a.created_at < :to AND ' . self::touchSql()
                . ($userId !== null ? ' AND l.user_id = :uid' : ''),
            ['from' => self::utc($monday), 'to' => self::utc($nextMonday)] + ($userId !== null ? ['uid' => $userId] : [])
        );

        $perDay = [];
        foreach ($rows as $row) {
            $day = (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))
                ->setTimezone(Clock::timezone())->format('Y-m-d');
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        $days = [];
        $today = Clock::today();
        for ($d = $monday; $d < $nextMonday; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $days[] = ['label' => $d->format('D'), 'date' => $date, 'count' => $perDay[$date] ?? 0, 'today' => $date === $today];
        }

        // The team's goal is everybody's added up.
        $perPerson = max(1, Settings::int('weekly_touch_goal', 25));
        // Counted in PHP: "loop" is a reserved word in MySQL.
        $people = $userId !== null ? 1 : max(1, count(array_filter(
            Users::all(true),
            static fn (array $u): bool => Users::isRunnableLoop((string) ($u['loop'] ?? ''))
        )));

        return ['this' => $current, 'last' => $last, 'days' => $days, 'goal' => $perPerson * $people];
    }

    /**
     * Follow-ups scheduled over the next seven days, a column per day.
     *
     * @return list<array{date: string, label: string, sub: string, today: bool, items: list<array<string, mixed>>}>
     */
    public static function upcoming(?int $userId): array
    {
        $start = Clock::local()->setTime(0, 0);
        $end = $start->modify('+7 days');

        $rows = Database::all(
            'SELECT r.id, r.due_at, r.note, l.id AS lead_id, l.company, l.decision_maker
             FROM reminders r JOIN leads l ON l.id = r.lead_id
             WHERE r.done_at IS NULL AND r.due_at > :now AND r.due_at < :end AND l.archived_at IS NULL'
                . ($userId !== null ? ' AND r.user_id = :uid' : '') . '
             ORDER BY r.due_at ASC',
            ['now' => Clock::now(), 'end' => self::utc($end)] + ($userId !== null ? ['uid' => $userId] : [])
        );

        $byDay = [];
        foreach ($rows as $row) {
            $local = (new DateTimeImmutable((string) $row['due_at'], new DateTimeZone('UTC')))->setTimezone(Clock::timezone());
            $row['time'] = $local->format('g:ia');
            $byDay[$local->format('Y-m-d')][] = $row;
        }

        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'label' => $d == $start ? 'Today' : ($d == $start->modify('+1 day') ? 'Tomorrow' : $d->format('l')),
                'sub' => $d->format('M j'),
                'today' => $d == $start,
                'items' => $byDay[$date] ?? [],
            ];
        }

        return $days;
    }

    /**
     * Leads → reached → meeting → signed, with the rate between each step.
     * Disqualified leads are left out: they were never really in the funnel.
     *
     * @return list<array{key: string, label: string, count: int, rate: int|null, link: array<string, string>}>
     */
    public static function funnel(?int $userId): array
    {
        $scope = $userId !== null ? ' AND user_id = :uid' : '';
        $params = $userId !== null ? ['uid' => $userId] : [];
        $count = static fn (string $where): int => (int) Database::scalar(
            "SELECT COUNT(*) FROM leads WHERE archived_at IS NULL AND status <> 'disqualified'{$scope} AND {$where}",
            $params
        );

        $leads = $count('1 = 1');
        $reached = $count("(status <> 'new' OR last_action IS NOT NULL)");
        $meetings = $count("status IN ('meeting', 'signed')");
        $signed = $count("status = 'signed'");

        $rate = static fn (int $part, int $whole): ?int => $whole > 0 ? (int) round($part / $whole * 100) : null;

        return [
            ['key' => 'leads', 'label' => 'Leads', 'count' => $leads, 'rate' => null, 'link' => []],
            ['key' => 'reached', 'label' => 'Reached out', 'count' => $reached, 'rate' => $rate($reached, $leads), 'link' => ['contacted' => '1']],
            ['key' => 'meeting', 'label' => 'Meeting', 'count' => $meetings, 'rate' => $rate($meetings, $reached), 'link' => ['status' => 'meeting']],
            ['key' => 'signed', 'label' => 'Signed', 'count' => $signed, 'rate' => $rate($signed, $meetings), 'link' => ['status' => 'signed']],
        ];
    }

    /** @return array<string, int> */
    private static function tally(?int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $params = ['from' => self::utc($from), 'to' => self::utc($to)] + ($userId !== null ? ['uid' => $userId] : []);
        $scope = $userId !== null ? ' AND l.user_id = :uid' : '';

        $count = static fn (string $where): int => (int) Database::scalar(
            "SELECT COUNT(*) FROM activities a JOIN leads l ON l.id = a.lead_id
             WHERE a.created_at >= :from AND a.created_at < :to{$scope} AND ({$where})",
            $params
        );

        // Status rows carry their status in the action column from now on; the
        // wording is the fallback for anything recorded before that.
        $status = static fn (string $key, string $label): string =>
            "a.type = 'status' AND (a.action = 'status:{$key}' OR (a.action IS NULL AND a.body LIKE 'Status set to {$label}%'))";

        return [
            'calls' => $count("a.type = 'action' AND a.action IN ('called', 'voicemail')"),
            'emails' => $count("(a.type = 'action' AND a.action = 'emailed') OR a.type = 'email'"),
            'meetings' => $count($status('meeting', 'Meeting booked')),
            'wins' => $count($status('signed', 'Signed')),
            'touches' => $count(self::touchSql()),
        ];
    }

    /** Anything that reached the person: a logged action, or a send from Prospector. */
    private static function touchSql(): string
    {
        return "(a.type = 'action' OR a.type = 'email' OR (a.type = 'note' AND a.body LIKE 'Texted:%'))";
    }

    private static function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', strtotime(Clock::now() . ' UTC') - $days * 86400);
    }

    private static function utc(DateTimeImmutable $local): string
    {
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param list<string> $values fixed, known strings only */
    private static function inList(array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => "'" . $v . "'", $values));
    }
}
