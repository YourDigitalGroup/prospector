<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Database;

/**
 * What a seller needs telling when they open the tool.
 *
 * The problem this solves: a batch lands at half past seven, the brief goes out
 * by email, and if that email is not read the ten leads sit there indefinitely
 * with nothing on any screen saying so. The Leads count in the sidebar is a
 * total, which does not change shape when a new batch arrives, so it cannot be
 * the thing that tells you.
 *
 * **The signal is "nobody has opened these", not "this arrived today".** Those
 * sound like the same notice and are not. A batch that landed this morning and
 * has already been worked through needs no notice at all; a batch from Monday
 * that nobody ever looked at needs one more than today's does, and gets more
 * urgent rather than less as it ages. Keying off opens also means the notice
 * clears itself by being acted on — there is no dismiss button to click past,
 * and no stored "seen" flag that can drift out of step with the work.
 *
 * An admin sees these per owner, because "Billy has ten nobody has touched" is
 * a different and more useful sentence than a single number across everybody.
 */
final class Notices
{
    /** Older than this and a batch is stale rather than merely unopened. */
    private const STALE_DAYS = 2;

    /** No more than this on screen at once; the rest are summarised. */
    private const MAX_BATCHES = 4;

    /**
     * How many leads nobody has opened.
     *
     * The number in the topbar. Archived leads are excluded: somebody who
     * archived a lead without opening it has dealt with it, and counting that
     * as unread would be arguing with them.
     */
    public static function unopenedCount(?int $userId = null): int
    {
        $scope = $userId !== null ? ' AND user_id = :uid' : '';
        $params = $userId !== null ? ['uid' => $userId] : [];

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM leads
             WHERE opened_at IS NULL AND archived_at IS NULL{$scope}",
            $params
        );
    }

    /**
     * The batches with unopened leads in them, newest first.
     *
     * One row per run, carrying enough to write a sentence about it: whose it
     * is, when it landed, how many are in it and how many of those nobody has
     * opened. A run whose leads have all been opened does not appear at all.
     *
     * @return list<array{
     *     run_id: int, user_id: int, owner_name: string, run_date: string,
     *     trigger_source: string, unopened: int, total: int, age_days: int, stale: bool
     * }>
     */
    public static function unopenedBatches(?int $userId = null): array
    {
        $scope = $userId !== null ? ' AND l.user_id = :uid' : '';
        $params = $userId !== null ? ['uid' => $userId] : [];

        $rows = Database::all(
            "SELECT l.run_id,
                    l.user_id,
                    u.name AS owner_name,
                    r.run_date,
                    r.trigger_source,
                    SUM(CASE WHEN l.opened_at IS NULL THEN 1 ELSE 0 END) AS unopened,
                    COUNT(*) AS total
             FROM leads l
             JOIN runs r ON r.id = l.run_id
             JOIN users u ON u.id = l.user_id
             WHERE l.archived_at IS NULL
               AND l.run_id IS NOT NULL{$scope}
             GROUP BY l.run_id, l.user_id, u.name, r.run_date, r.trigger_source
             HAVING SUM(CASE WHEN l.opened_at IS NULL THEN 1 ELSE 0 END) > 0
             ORDER BY r.run_date DESC, l.run_id DESC",
            $params
        );

        $today = Clock::today();
        $batches = [];

        foreach ($rows as $row) {
            $date = (string) $row['run_date'];
            $age = self::daysBetween($date, $today);

            $batches[] = [
                'run_id' => (int) $row['run_id'],
                'user_id' => (int) $row['user_id'],
                'owner_name' => (string) $row['owner_name'],
                'run_date' => $date,
                'trigger_source' => (string) $row['trigger_source'],
                'unopened' => (int) $row['unopened'],
                'total' => (int) $row['total'],
                'age_days' => $age,
                // Two days is the line: yesterday's untouched batch is normal
                // if you were out, and Monday's still untouched on Thursday is
                // not, so they should not look the same on screen.
                'stale' => $age >= self::STALE_DAYS,
            ];
        }

        return $batches;
    }

    /**
     * The batches, ready for a banner: at most a handful, plus a count of what
     * was left off so nothing is silently hidden.
     *
     * @return array{batches: list<array<string, mixed>>, hidden: int, hidden_leads: int, unopened: int, stale: bool}
     */
    public static function digest(?int $userId = null): array
    {
        $all = self::unopenedBatches($userId);
        $shown = array_slice($all, 0, self::MAX_BATCHES);
        $rest = array_slice($all, self::MAX_BATCHES);

        $hiddenLeads = 0;
        foreach ($rest as $batch) {
            $hiddenLeads += $batch['unopened'];
        }

        $stale = false;
        foreach ($all as $batch) {
            if ($batch['stale']) {
                $stale = true;
                break;
            }
        }

        return [
            'batches' => $shown,
            'hidden' => count($rest),
            'hidden_leads' => $hiddenLeads,
            'unopened' => self::unopenedCount($userId),
            'stale' => $stale,
        ];
    }

    /**
     * How a batch's date should read on screen.
     *
     * "This morning" and "yesterday" rather than a date, because that is how
     * somebody thinks about a batch that just landed. Beyond that a weekday is
     * more use than a number — "Monday's batch" locates itself, "Sep 14" needs
     * working out.
     */
    public static function whenLabel(string $runDate, int $ageDays): string
    {
        if ($ageDays <= 0) {
            return 'this morning';
        }

        if ($ageDays === 1) {
            return 'yesterday';
        }

        $timestamp = strtotime($runDate);

        if ($timestamp === false) {
            return $runDate;
        }

        if ($ageDays < 7) {
            return date('l', $timestamp);
        }

        return date('M j', $timestamp);
    }

    /** Whole days between two Y-m-d dates, never negative. */
    private static function daysBetween(string $from, string $to): int
    {
        $a = strtotime($from . ' 00:00:00');
        $b = strtotime($to . ' 00:00:00');

        if ($a === false || $b === false) {
            return 0;
        }

        return max(0, (int) round(($b - $a) / 86400));
    }
}
