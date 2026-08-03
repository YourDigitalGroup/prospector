<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Database;

final class Leads
{
    /** Disposition values, mirroring the feedback loop the prospector skills ask for. */
    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'no_answer' => 'No answer',
        'meeting' => 'Meeting booked',
        'signed' => 'Signed',
        'not_interested' => 'Not interested',
        'disqualified' => 'Disqualified',
    ];

    /** Statuses that count as an active, working lead. */
    public const OPEN_STATUSES = ['new', 'contacted', 'no_answer', 'meeting'];

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** Normalized company name used for cross-batch de-duplication. */
    public static function companyKey(string $company): string
    {
        $key = strtolower(trim($company));
        $key = preg_replace('/&/', ' and ', $key) ?? $key;
        $key = preg_replace('/[^a-z0-9 ]+/', ' ', $key) ?? $key;
        $key = preg_replace(
            '/\b(the|inc|llc|ltd|co|corp|corporation|company|group|holdings|media|communications|broadcasting|enterprises)\b/',
            ' ',
            $key
        ) ?? $key;
        $key = trim(preg_replace('/\s+/', ' ', $key) ?? $key);

        return $key === '' ? strtolower(trim($company)) : $key;
    }

    /**
     * Insert a lead unless this owner already has the same company on file.
     *
     * @param array<string, mixed> $lead
     * @return int 0 when skipped as a duplicate
     */
    public static function create(int $userId, ?int $runId, array $lead): int
    {
        $company = trim((string) ($lead['company'] ?? ''));
        if ($company === '') {
            return 0;
        }

        $key = self::companyKey($company);

        $existing = Database::scalar(
            'SELECT id FROM leads WHERE user_id = :uid AND company_key = :key',
            ['uid' => $userId, 'key' => $key]
        );
        if ($existing !== null) {
            return 0;
        }

        $now = Clock::now();
        $score = (int) ($lead['fit_score'] ?? 0);

        return Database::insert('leads', [
            'user_id' => $userId,
            'run_id' => $runId,
            'company' => mb_substr($company, 0, 190),
            'company_key' => mb_substr($key, 0, 190),
            'website' => self::clean($lead['website'] ?? null, 255),
            'vertical' => self::clean($lead['vertical'] ?? null, 80),
            'door' => self::clean($lead['door'] ?? null, 80),
            'market' => self::clean($lead['market'] ?? null, 120),
            'state' => self::clean($lead['state'] ?? null, 40),
            'decision_maker' => self::clean($lead['decision_maker'] ?? null, 190),
            'title' => self::clean($lead['title'] ?? null, 190),
            'email' => self::clean($lead['email'] ?? null, 190),
            'email_confidence' => self::clean($lead['email_confidence'] ?? null, 20),
            'phone' => self::clean($lead['phone'] ?? null, 60),
            'direct_phone' => self::clean($lead['direct_phone'] ?? null, 60),
            'linkedin' => self::clean($lead['linkedin'] ?? null, 255),
            'fit_score' => max(0, min(100, $score)),
            'why' => self::clean($lead['why'] ?? null, 2000),
            'hook' => self::clean($lead['hook'] ?? null, 2000),
            'evidence' => isset($lead['evidence']) && $lead['evidence'] !== []
                ? json_encode($lead['evidence'], JSON_UNESCAPED_SLASHES)
                : null,
            'source' => self::clean($lead['source'] ?? null, 190),
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function clean(mixed $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        $value = trim((string) $value);
        if ($value === '' || strtolower($value) === 'null' || strtolower($value) === 'n/a') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT l.*, u.name AS owner_name, u.email AS owner_email
             FROM leads l JOIN users u ON u.id = l.user_id
             WHERE l.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Build the WHERE clause shared by the list view, counts and CSV export.
     *
     * @param array<string, mixed> $filters
     * @return array{sql: string, params: array<string, mixed>}
     */
    private static function conditions(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'l.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'l.status = :status';
            $params['status'] = (string) $filters['status'];
        } elseif (!empty($filters['open_only'])) {
            $in = implode(',', array_map(static fn (string $s): string => "'" . $s . "'", self::OPEN_STATUSES));
            $where[] = "l.status IN ({$in})";
        }

        if (!empty($filters['vertical'])) {
            $where[] = 'l.vertical = :vertical';
            $params['vertical'] = (string) $filters['vertical'];
        }

        if (!empty($filters['door'])) {
            $where[] = 'l.door = :door';
            $params['door'] = (string) $filters['door'];
        }

        if (!empty($filters['run_id'])) {
            $where[] = 'l.run_id = :run_id';
            $params['run_id'] = (int) $filters['run_id'];
        }

        if (isset($filters['min_score']) && $filters['min_score'] !== '') {
            $where[] = 'l.fit_score >= :min_score';
            $params['min_score'] = (int) $filters['min_score'];
        }

        if (!empty($filters['in_ghl'])) {
            $where[] = $filters['in_ghl'] === 'yes'
                ? 'l.ghl_contact_id IS NOT NULL'
                : 'l.ghl_contact_id IS NULL';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'l.created_at >= :date_from';
            $params['date_from'] = (string) $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['search'])) {
            $where[] = '(l.company LIKE :search OR l.decision_maker LIKE :search
                         OR l.email LIKE :search OR l.market LIKE :search OR l.why LIKE :search)';
            $params['search'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
        }

        if (empty($filters['include_archived'])) {
            $where[] = 'l.archived_at IS NULL';
        }

        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        $c = self::conditions($filters);

        $sorts = [
            'newest' => 'l.created_at DESC, l.fit_score DESC',
            'oldest' => 'l.created_at ASC',
            'score' => 'l.fit_score DESC, l.created_at DESC',
            'company' => 'l.company ASC',
            'status' => 'l.status ASC, l.fit_score DESC',
        ];
        $order = $sorts[(string) ($filters['sort'] ?? 'newest')] ?? $sorts['newest'];

        return Database::all(
            "SELECT l.*, u.name AS owner_name
             FROM leads l JOIN users u ON u.id = l.user_id
             WHERE {$c['sql']}
             ORDER BY {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $c['params']
        );
    }

    /** @param array<string, mixed> $filters */
    public static function count(array $filters): int
    {
        $c = self::conditions($filters);

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM leads l WHERE {$c['sql']}",
            $c['params']
        );
    }

    /**
     * Dashboard tiles.
     *
     * @return array<string, int>
     */
    public static function stats(?int $userId = null): array
    {
        $params = [];
        $scope = '';
        if ($userId !== null) {
            $scope = ' AND user_id = :uid';
            $params['uid'] = $userId;
        }

        $today = Clock::today();

        $counts = [];
        foreach (array_keys(self::STATUSES) as $status) {
            $counts[$status] = (int) Database::scalar(
                "SELECT COUNT(*) FROM leads WHERE archived_at IS NULL AND status = :s{$scope}",
                $params + ['s' => $status]
            );
        }

        $counts['total'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM leads WHERE archived_at IS NULL{$scope}",
            $params
        );
        $counts['today'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM leads l
             WHERE l.archived_at IS NULL{$scope}
               AND l.run_id IN (SELECT id FROM runs WHERE run_date = :today)",
            $params + ['today' => $today]
        );
        $counts['in_ghl'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM leads WHERE archived_at IS NULL AND ghl_contact_id IS NOT NULL{$scope}",
            $params
        );
        $counts['high_fit'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM leads WHERE archived_at IS NULL AND fit_score >= 85{$scope}",
            $params
        );

        $worked = $counts['total'] - $counts['new'];
        $counts['worked'] = max(0, $worked);
        $counts['conversion'] = $counts['total'] > 0
            ? (int) round(($counts['meeting'] + $counts['signed']) / $counts['total'] * 100)
            : 0;

        return $counts;
    }

    /**
     * Distinct filter options actually present in the data, so the dropdowns
     * never offer a value that returns nothing.
     *
     * @return array{verticals: list<string>, doors: list<string>}
     */
    public static function facets(?int $userId = null): array
    {
        $params = [];
        $scope = '';
        if ($userId !== null) {
            $scope = ' AND user_id = :uid';
            $params['uid'] = $userId;
        }

        $pluck = static function (array $rows, string $column): array {
            $values = [];
            foreach ($rows as $row) {
                $value = (string) ($row[$column] ?? '');
                if ($value !== '') {
                    $values[] = $value;
                }
            }

            return $values;
        };

        return [
            'verticals' => $pluck(Database::all(
                "SELECT DISTINCT vertical FROM leads WHERE vertical IS NOT NULL{$scope} ORDER BY vertical",
                $params
            ), 'vertical'),
            'doors' => $pluck(Database::all(
                "SELECT DISTINCT door FROM leads WHERE door IS NOT NULL{$scope} ORDER BY door",
                $params
            ), 'door'),
        ];
    }

    public static function setStatus(int $leadId, string $status, ?string $note, int $actorId): bool
    {
        if (!array_key_exists($status, self::STATUSES)) {
            return false;
        }

        $lead = self::find($leadId);
        if ($lead === null) {
            return false;
        }

        $data = ['status' => $status, 'updated_at' => Clock::now()];
        if ($note !== null && $note !== '') {
            $data['owner_note'] = mb_substr($note, 0, 2000);
        }

        Database::update('leads', $data, ['id' => $leadId]);

        $body = 'Status set to ' . self::statusLabel($status);
        if ($note !== null && $note !== '') {
            $body .= ' — ' . $note;
        }
        self::addActivity($leadId, $actorId, 'status', $body);

        return true;
    }

    public static function archive(int $leadId, int $actorId): void
    {
        Database::update('leads', ['archived_at' => Clock::now(), 'updated_at' => Clock::now()], ['id' => $leadId]);
        self::addActivity($leadId, $actorId, 'archive', 'Lead archived');
    }

    public static function restore(int $leadId, int $actorId): void
    {
        Database::update('leads', ['archived_at' => null, 'updated_at' => Clock::now()], ['id' => $leadId]);
        self::addActivity($leadId, $actorId, 'restore', 'Lead restored');
    }

    public static function reassign(int $leadId, int $newOwnerId, int $actorId): void
    {
        $owner = Users::find($newOwnerId);
        if ($owner === null) {
            return;
        }

        Database::update('leads', ['user_id' => $newOwnerId, 'updated_at' => Clock::now()], ['id' => $leadId]);
        self::addActivity($leadId, $actorId, 'reassign', 'Reassigned to ' . $owner['name']);
    }

    public static function addActivity(int $leadId, ?int $userId, string $type, string $body): void
    {
        Database::insert('activities', [
            'lead_id' => $leadId,
            'user_id' => $userId,
            'type' => $type,
            'body' => mb_substr($body, 0, 4000),
            'created_at' => Clock::now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function activities(int $leadId): array
    {
        return Database::all(
            'SELECT a.*, u.name AS actor_name
             FROM activities a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.lead_id = :id ORDER BY a.id DESC',
            ['id' => $leadId]
        );
    }

    /**
     * Company names already delivered to this owner — fed back into the prompt
     * so a batch never repeats a previous one.
     *
     * @return list<string>
     */
    public static function sentCompanies(int $userId, int $days = 365): array
    {
        $cutoff = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $rows = Database::all(
            'SELECT company FROM leads WHERE user_id = :uid AND created_at >= :cutoff ORDER BY company',
            ['uid' => $userId, 'cutoff' => $cutoff]
        );

        return array_values(array_map(static fn (array $r): string => (string) $r['company'], $rows));
    }

    /** @return list<array<string, mixed>> */
    public static function forRun(int $runId): array
    {
        return Database::all(
            'SELECT * FROM leads WHERE run_id = :rid ORDER BY fit_score DESC, company ASC',
            ['rid' => $runId]
        );
    }

    public static function markSyncedToGhl(int $leadId, string $contactId): void
    {
        Database::update(
            'leads',
            ['ghl_contact_id' => $contactId, 'ghl_synced_at' => Clock::now(), 'updated_at' => Clock::now()],
            ['id' => $leadId]
        );
    }

    /** Weekly lead volume for the dashboard sparkline. */
    public static function dailyVolume(?int $userId, int $days = 14): array
    {
        $series = [];
        $today = Clock::local();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify("-{$i} days")->format('Y-m-d');
            $params = ['d' => $date];
            $scope = '';
            if ($userId !== null) {
                $scope = ' AND l.user_id = :uid';
                $params['uid'] = $userId;
            }

            $series[] = [
                'date' => $date,
                'count' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM leads l
                     WHERE l.run_id IN (SELECT id FROM runs WHERE run_date = :d){$scope}",
                    $params
                ),
            ];
        }

        return $series;
    }
}
