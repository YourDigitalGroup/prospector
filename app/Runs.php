<?php

declare(strict_types=1);

namespace Prospector;

use DateTimeImmutable;
use Prospector\Support\Clock;
use Prospector\Support\Database;

final class Runs
{
    /**
     * Vertical rotation per loop. The partner loop follows the day-of-week
     * rotation the skill specifies; the client loop runs mixed batches with a
     * rotating emphasis so consecutive days don't hammer one vertical.
     */
    private const PARTNER_ROTATION = [
        1 => 'Radio broadcasters',
        2 => 'Advertising agencies',
        3 => 'TV broadcasters',
        4 => 'Local and regional publishers',
        5 => 'Out-of-home operators, plus one wildcard from any vertical',
        6 => 'Wildcard — any of the four verticals',
        7 => 'Wildcard — any of the four verticals',
    ];

    private const CLIENT_ROTATION = [
        1 => 'Mixed batch, lean healthcare',
        2 => 'Mixed batch, lean higher education',
        3 => 'Mixed batch, lean casinos and gaming',
        4 => 'Mixed batch, lean agriculture',
        5 => 'Mixed batch, lean regional retail',
        6 => 'Mixed batch, no vertical emphasis',
        7 => 'Mixed batch, no vertical emphasis',
    ];

    private const HOME_ROTATION = [
        1 => 'Mixed batch, lean remodelers and general contractors',
        2 => 'Mixed batch, lean specialty trades — HVAC, plumbing, electrical, roofing',
        3 => 'Mixed batch, lean interior design and residential architecture',
        4 => 'Mixed batch, lean home retail — furniture, paint, flooring, lighting, kitchen and bath',
        5 => 'Mixed batch, lean outdoor and exterior — landscaping, decks, pools, windows',
        6 => 'Mixed batch, no vertical emphasis',
        7 => 'Mixed batch, no vertical emphasis',
    ];

    /** Metro rotation so geography moves day to day. */
    private const PARTNER_GEOS = [
        'Upper Midwest — South Dakota, North Dakota, Minnesota',
        'Iowa, Nebraska and Kansas',
        'Wisconsin and Michigan',
        'Montana, Wyoming and Idaho',
        'Missouri, Arkansas and Oklahoma',
        'Texas secondary markets',
        'Southeast — Georgia, Alabama, Tennessee, the Carolinas',
        'Northeast — upstate New York, Pennsylvania, New England',
        'Mountain West — Colorado, Utah, New Mexico, Arizona',
        'Pacific Northwest — Washington, Oregon',
        'Ohio Valley — Ohio, Indiana, Kentucky',
        'National — any qualifying independent operator',
    ];

    private const CLIENT_GEOS = [
        'South Dakota — Sioux Falls, Rapid City and surrounding markets',
        'North Dakota — Fargo, Bismarck, Grand Forks',
        'Minnesota — outside the Twin Cities metro core',
        'Iowa — Des Moines, Cedar Rapids, Sioux City, Davenport',
        'Nebraska — Omaha, Lincoln, Grand Island',
        'Wisconsin — Madison, Green Bay, Eau Claire, La Crosse',
        'Montana and Wyoming',
        'Minnesota — Twin Cities metro suburbs and exurbs',
        'Upper Midwest — open, rotate metros not covered recently',
    ];

    /**
     * Every entry sits inside 100 driving miles of Sioux Falls, because the
     * radius is the whole point of this loop — it is what keeps Sara and Darren
     * off each other's prospects. The spec states the boundary as a hard rule;
     * this rotation just moves the emphasis around inside it.
     */
    private const HOME_GEOS = [
        'Sioux Falls and its ring — Brandon, Harrisburg, Tea, Dell Rapids, Canton',
        'Siouxland — Sioux City, North Sioux City, Dakota Dunes, Le Mars, Sergeant Bluff',
        'I-29 north — Brookings, Madison, Flandreau, Watertown',
        'James River valley — Mitchell, Yankton, Vermillion, Parkston',
        'Southwest Minnesota — Luverne, Pipestone, Worthington, Windom, Marshall',
        'Northwest Iowa — Rock Rapids, Sheldon, Spencer, Spirit Lake, Storm Lake',
        'Open — anywhere inside the 100-mile ring not covered recently',
    ];

    public static function verticalFor(string $loop, ?DateTimeImmutable $when = null): string
    {
        $when ??= Clock::local();
        $dow = (int) $when->format('N');

        $rotation = match ($loop) {
            'partner' => self::PARTNER_ROTATION,
            'home' => self::HOME_ROTATION,
            default => self::CLIENT_ROTATION,
        };

        return $rotation[$dow] ?? $rotation[1];
    }

    public static function geographyFor(string $loop, ?DateTimeImmutable $when = null): string
    {
        $when ??= Clock::local();

        $list = match ($loop) {
            'partner' => self::PARTNER_GEOS,
            'home' => self::HOME_GEOS,
            default => self::CLIENT_GEOS,
        };

        $index = ((int) $when->format('z')) % count($list);

        return $list[$index];
    }

    public static function loopLabel(string $loop): string
    {
        return match ($loop) {
            'partner' => 'Partner Prospector',
            'client' => 'Client Prospector',
            'home' => 'Home Prospector',
            default => 'No loop',
        };
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT r.*, u.name AS owner_name, u.email AS owner_email
             FROM runs r JOIN users u ON u.id = r.user_id WHERE r.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<string, mixed>|null */
    public static function forUserOnDate(int $userId, string $date): ?array
    {
        return Database::first(
            'SELECT * FROM runs WHERE user_id = :uid AND run_date = :d ORDER BY id DESC LIMIT 1',
            ['uid' => $userId, 'd' => $date]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function recent(?int $userId = null, int $limit = 30): array
    {
        $where = $userId !== null ? 'WHERE r.user_id = :uid' : '';
        $params = $userId !== null ? ['uid' => $userId] : [];

        return Database::all(
            "SELECT r.*, u.name AS owner_name
             FROM runs r JOIN users u ON u.id = r.user_id
             {$where}
             ORDER BY r.id DESC LIMIT {$limit}",
            $params
        );
    }

    public static function start(int $userId, string $loop, string $date, string $trigger, string $vertical, string $geography, string $model): int
    {
        return Database::insert('runs', [
            'user_id' => $userId,
            'loop' => $loop,
            'run_date' => $date,
            'status' => 'running',
            'trigger_source' => $trigger,
            'vertical' => $vertical,
            'geography' => $geography,
            'model' => $model,
            'started_at' => Clock::now(),
        ]);
    }

    /**
     * The run that hand-entered leads hang off — one per owner per day, made
     * on demand and reused for the rest of that day.
     *
     * A lead typed in by hand did not come from a batch, so run_id could
     * honestly be null. It is not, because too much of the app counts through
     * runs: "leads today" on the dashboard and the fortnight sparkline both ask
     * which run a lead belongs to and what date that run carries. A null there
     * means eight leads entered after a conference show up as a flat zero for
     * the day, which is worse than slightly stretching what a run is. One row a
     * day also keeps /runs readable — a stack of business cards becomes a
     * single "Added by hand" entry rather than eight.
     */
    public static function handEntered(int $userId, string $loop, string $date): int
    {
        $existing = Database::scalar(
            "SELECT id FROM runs
             WHERE user_id = :uid AND run_date = :d AND trigger_source = 'manual'
             ORDER BY id DESC LIMIT 1",
            ['uid' => $userId, 'd' => $date]
        );

        if ($existing !== null) {
            return (int) $existing;
        }

        $runId = self::start($userId, $loop, $date, 'manual', 'Added by hand', '', 'none');

        Database::update('runs', [
            'status' => 'success',
            'brief_md' => "## Added by hand\n\nLeads typed in directly rather than found by a batch.\n",
            'finished_at' => Clock::now(),
        ], ['id' => $runId]);

        return $runId;
    }

    /**
     * Recount a run from the leads that actually point at it.
     *
     * Derived rather than incremented, so it stays right when a lead is later
     * deleted — the hand-entry run is added to all day and there is no single
     * moment at which it is finished.
     */
    public static function recount(int $runId): void
    {
        Database::update('runs', [
            'lead_count' => (int) Database::scalar(
                'SELECT COUNT(*) FROM leads WHERE run_id = :rid',
                ['rid' => $runId]
            ),
        ], ['id' => $runId]);
    }

    /** @param array<string, mixed> $data */
    public static function finish(int $runId, array $data): void
    {
        $data['finished_at'] = Clock::now();
        Database::update('runs', $data, ['id' => $runId]);
    }

    public static function fail(int $runId, string $error): void
    {
        self::finish($runId, ['status' => 'failed', 'error' => mb_substr($error, 0, 4000)]);
    }

    public static function markEmailed(int $runId): void
    {
        Database::update('runs', ['emailed_at' => Clock::now()], ['id' => $runId]);
    }

    /** Is a run currently in flight for this user? Prevents overlapping batches. */
    public static function isRunning(int $userId): bool
    {
        $row = Database::first(
            "SELECT id, started_at FROM runs
             WHERE user_id = :uid AND status = 'running'
             ORDER BY id DESC LIMIT 1",
            ['uid' => $userId]
        );

        if ($row === null) {
            return false;
        }

        // A run wedged for over an hour is treated as dead so a stuck row can
        // never block every future batch.
        $started = strtotime((string) $row['started_at'] . ' UTC');
        if ($started !== false && $started < time() - 3600) {
            self::fail((int) $row['id'], 'Run timed out — no result after 60 minutes.');

            return false;
        }

        return true;
    }
}
