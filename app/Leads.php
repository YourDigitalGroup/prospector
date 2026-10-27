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

    /**
     * Things a person does to a lead, as opposed to what the lead now is.
     *
     * A status answers "where has this got to" and there is one of them. An
     * action answers "what did I do, and when", and there are as many as the
     * work takes — three calls, two emails and a visit is an ordinary week on
     * one lead, and a single field cannot hold that.
     *
     * Deliberately short. A list long enough to need reading is a list people
     * stop filling in, and an unlogged call is worse than a roughly-labelled
     * one. Anything that does not fit goes in the note beside it.
     */
    public const ACTIONS = [
        'called' => 'Called',
        'voicemail' => 'Left a voicemail',
        'emailed' => 'Emailed',
        'texted' => 'Texted',
        'met' => 'Met in person',
        'linkedin' => 'LinkedIn message',
        'proposal' => 'Sent a proposal',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * The same actions at phone width, where the tag shares a 390px line with
     * the company name.
     *
     * "Left a voicemail · 6w" crowds the company down to "Haldane …", and the
     * company is the thing you are scanning for. The full wording stays in the
     * tag's title and on every other screen.
     */
    private const ACTIONS_SHORT = [
        'called' => 'Call',
        'voicemail' => 'VM',
        'emailed' => 'Email',
        'texted' => 'Text',
        'met' => 'Met',
        'linkedin' => 'LinkedIn',
        'proposal' => 'Proposal',
    ];

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    public static function actionShort(string $action): string
    {
        return self::ACTIONS_SHORT[$action] ?? self::actionLabel($action);
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
     * Identify the person a lead is about, for de-duplication.
     *
     * One organisation can be worth several leads — the marketing director and
     * the GM are different conversations — so a duplicate is the same *person*
     * at the same company, not merely the same company.
     *
     * The email wins when there is one, because two spellings of a name are
     * common and two spellings of an address are not. With no email the name
     * carries it, and with neither the key is empty, which stands for "the
     * company itself, nobody named" — a real thing to have exactly one of.
     *
     * @param array<string, mixed> $lead
     */
    public static function contactKey(array $lead): string
    {
        $email = strtolower(trim((string) ($lead['email'] ?? '')));
        if ($email !== '') {
            return mb_substr($email, 0, 190);
        }

        $person = strtolower(trim((string) ($lead['decision_maker'] ?? '')));
        $person = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $person) ?? $person);
        $person = trim(preg_replace('/\s+/', ' ', $person) ?? $person);

        return mb_substr($person, 0, 190);
    }

    /**
     * The lead this one would collide with, if there is one.
     *
     * create() answers "did it go in" with a 0, which is all a bulk import
     * needs. A person typing a lead in wants the other half of that sentence:
     * which record they are looking at and how to get to it.
     *
     * @param array<string, mixed> $lead
     * @return array<string, mixed>|null
     */
    public static function findDuplicate(int $userId, array $lead): ?array
    {
        $company = trim((string) ($lead['company'] ?? ''));

        if ($company === '') {
            return null;
        }

        return Database::first(
            'SELECT * FROM leads WHERE user_id = :uid AND company_key = :key AND contact_key = :contact',
            [
                'uid' => $userId,
                'key' => self::companyKey($company),
                'contact' => self::contactKey($lead),
            ]
        );
    }

    /**
     * What an upload may change on a lead it matches.
     *
     * Facts about the company and the person, and nothing else. Deliberately
     * absent: status, owner_note, the activity timeline, ghl_contact_id,
     * opened_at, archived_at, created_at and run_id. A spreadsheet should never
     * be able to move a lead you marked Signed back to New, unpick it from its
     * CRM contact, or make a batch you have already worked look unopened again.
     *
     * @return list<string>
     */
    public static function updatableFields(): array
    {
        return [
            'website', 'vertical', 'door', 'market', 'state',
            'decision_maker', 'title', 'email', 'email_confidence',
            'phone', 'direct_phone', 'linkedin', 'fit_score', 'why', 'hook', 'evidence',
        ];
    }

    /**
     * The lead an uploaded row is about, if this owner already has one.
     *
     * Two passes, because the identity key moves. contact_key is the email when
     * there is one and the person's name when there is not, so a lead stored
     * with a name and no address gets a *different* key once the address turns
     * up — and the single most common reason to re-upload a list is that the
     * addresses have since been found. Matching on the key alone would file
     * that row as a new person and leave two of them.
     *
     * So: the exact key first, then the same named person at the same company
     * whatever their key says. Two different people with the same name at one
     * company would collide, which is rare enough to accept and visible in the
     * preview before anything is written.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function matchFor(int $userId, array $row): ?array
    {
        $company = trim((string) ($row['company'] ?? ''));

        if ($company === '') {
            return null;
        }

        $key = self::companyKey($company);

        $exact = Database::first(
            'SELECT * FROM leads WHERE user_id = :uid AND company_key = :key AND contact_key = :contact',
            ['uid' => $userId, 'key' => $key, 'contact' => self::contactKey($row)]
        );

        if ($exact !== null) {
            return $exact;
        }

        $person = self::personKey((string) ($row['decision_maker'] ?? ''));

        if ($person === '') {
            return null;
        }

        // LOWER() in SQL rather than a stored column: this is one query on an
        // import, not a hot path, and another denormalised key is another thing
        // that can fall out of step.
        return Database::first(
            "SELECT * FROM leads
             WHERE user_id = :uid AND company_key = :key
               AND decision_maker IS NOT NULL
               AND LOWER(TRIM(decision_maker)) = :person
             ORDER BY id ASC LIMIT 1",
            ['uid' => $userId, 'key' => $key, 'person' => $person]
        );
    }

    private static function personKey(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * What an uploaded row would change on a lead already on file.
     *
     * The rule that matters: **a blank cell is not a value.** An empty column
     * never overwrites anything, in any mode. A spreadsheet exported without
     * the phone column should not wipe every phone number on file, and that is
     * exactly what "overwrite" would mean if taken literally. Clearing a field
     * stays a thing you do on the lead itself, deliberately, to one record.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     * @param string $mode 'fill' touches only empty fields; 'update' takes the
     *                     file's value wherever it supplies one
     * @return array<string, array{from: string, to: string}>
     */
    public static function changesFor(array $existing, array $row, string $mode): array
    {
        if ($mode !== 'fill' && $mode !== 'update') {
            return [];
        }

        $changes = [];

        foreach (self::updatableFields() as $field) {
            $incoming = self::incomingValue($field, $row);

            if ($incoming === null) {
                continue;
            }

            // Bucketed here rather than on the way to the database, so the
            // import preview shows the heading the lead will actually end up
            // under — the preview is a promise about the result.
            if ($field === 'vertical') {
                $incoming = self::canonicalVertical((int) $existing['user_id'], $incoming)['vertical'];
            }

            $current = self::currentValue($field, $existing);

            if ($current === $incoming) {
                continue;
            }

            // Fill mode defers to whatever is already there; update mode does
            // not. Both leave a field the file said nothing about alone.
            if ($mode === 'fill' && $current !== '') {
                continue;
            }

            $changes[$field] = ['from' => $current, 'to' => $incoming];
        }

        return $changes;
    }

    /**
     * The file's value for a field, or null when it did not supply one.
     *
     * @param array<string, mixed> $row
     */
    private static function incomingValue(string $field, array $row): ?string
    {
        if ($field === 'evidence') {
            $value = $row['evidence'] ?? null;
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        if ($field === 'fit_score') {
            // Unscored is stored as 0, so a blank column and a deliberate zero
            // are indistinguishable by the time they get here. Treating 0 as
            // "nothing said" is the safe reading: it cannot wipe a real score,
            // and nobody uploads a list to set everything to zero.
            $score = (int) ($row['fit_score'] ?? 0);

            return $score > 0 ? (string) $score : null;
        }

        $value = trim((string) ($row[$field] ?? ''));

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $existing */
    private static function currentValue(string $field, array $existing): string
    {
        if ($field === 'evidence') {
            $decoded = json_decode((string) ($existing['evidence'] ?? ''), true);

            if (is_array($decoded)) {
                return implode(', ', array_map('strval', $decoded));
            }

            return is_string($decoded) ? $decoded : trim((string) ($existing['evidence'] ?? ''));
        }

        if ($field === 'fit_score') {
            $score = (int) ($existing['fit_score'] ?? 0);

            return $score > 0 ? (string) $score : '';
        }

        return trim((string) ($existing[$field] ?? ''));
    }

    /**
     * Apply what changesFor worked out, and say so on the timeline.
     *
     * @param array<string, array{from: string, to: string}> $changes
     * @return int how many fields actually moved
     */
    public static function applyChanges(int $leadId, array $changes, ?int $actorId = null, string $source = 'an uploaded list'): int
    {
        if ($changes === []) {
            return 0;
        }

        $lead = self::find($leadId);

        if ($lead === null) {
            return 0;
        }

        $update = ['updated_at' => Clock::now()];

        foreach ($changes as $field => $change) {
            $value = $change['to'];

            if ($field === 'fit_score') {
                $update[$field] = max(0, min(100, (int) $value));
                continue;
            }

            if ($field === 'evidence') {
                $update[$field] = json_encode($value, JSON_UNESCAPED_SLASHES);
                continue;
            }

            $update[$field] = self::clean($value, self::lengthFor($field));
        }

        // The email and the name are what contact_key is derived from, so a
        // change to either has to move the key with it or de-duplication starts
        // matching on something the lead no longer says.
        if (isset($changes['email']) || isset($changes['decision_maker'])) {
            $update['contact_key'] = self::contactKey(array_merge($lead, [
                'email' => $changes['email']['to'] ?? $lead['email'],
                'decision_maker' => $changes['decision_maker']['to'] ?? $lead['decision_maker'],
            ]));
        }

        Database::update('leads', $update, ['id' => $leadId]);

        $described = [];
        foreach ($changes as $field => $change) {
            $label = str_replace('_', ' ', $field);
            $described[] = $change['from'] === ''
                ? $label . ' set to ' . mb_strimwidth($change['to'], 0, 60, '…')
                : $label . ': ' . mb_strimwidth($change['from'], 0, 30, '…')
                    . ' → ' . mb_strimwidth($change['to'], 0, 40, '…');
        }

        self::addActivity(
            $leadId,
            $actorId,
            'note',
            'Updated from ' . $source . ' — ' . implode('; ', $described)
        );

        return count($changes);
    }

    /** Column widths, so an update truncates the same way an insert does. */
    private static function lengthFor(string $field): int
    {
        return match ($field) {
            'website', 'linkedin' => 255,
            'vertical', 'door' => 80,
            'market' => 120,
            'state' => 40,
            'email_confidence' => 20,
            'phone', 'direct_phone' => 60,
            'why', 'hook' => 2000,
            default => 190,
        };
    }

    /**
     * Insert a lead unless this owner already has that person at that company.
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
        $contact = self::contactKey($lead);

        $existing = Database::scalar(
            'SELECT id FROM leads WHERE user_id = :uid AND company_key = :key AND contact_key = :contact',
            ['uid' => $userId, 'key' => $key, 'contact' => $contact]
        );
        if ($existing !== null) {
            return 0;
        }

        $now = Clock::now();
        $score = (int) ($lead['fit_score'] ?? 0);

        // Free text in, one of the loop's own headings out — so the filter
        // offers "Casino" once rather than offering the fifteen ways a casino
        // can be described. The words that arrived are kept beside it when
        // they said more.
        $vertical = self::canonicalVertical($userId, (string) ($lead['vertical'] ?? ''));

        return Database::insert('leads', [
            'user_id' => $userId,
            'run_id' => $runId,
            'company' => mb_substr($company, 0, 190),
            'company_key' => mb_substr($key, 0, 190),
            'contact_key' => $contact,
            'website' => self::clean($lead['website'] ?? null, 255),
            'vertical' => self::clean($vertical['vertical'], 80),
            'vertical_detail' => self::clean($vertical['detail'], 120),
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

    /**
     * File a free-text vertical under the owning loop's heading.
     *
     * The loop comes from the owner rather than being passed in, because every
     * caller that writes a lead already knows the owner and none of them know
     * the loop. Cached per user: an import runs this once a row, and the answer
     * cannot change inside one request.
     *
     * @return array{vertical: string, detail: string}
     */
    private static function canonicalVertical(int $userId, string $raw): array
    {
        static $loops = [];

        if (!array_key_exists($userId, $loops)) {
            $owner = Users::find($userId);
            $loops[$userId] = (string) ($owner['loop'] ?? '');
        }

        $canonical = Verticals::canonical($raw, $loops[$userId]);

        return ['vertical' => $canonical, 'detail' => Verticals::detail($raw, $canonical)];
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

    /**
     * Leads keyed by their GoHighLevel contact id.
     *
     * Lets a screen full of GoHighLevel records link back to the leads they came
     * from in one query rather than one per row.
     *
     * @param list<string> $contactIds
     * @return array<string, array<string, mixed>>
     */
    public static function byGhlContactIds(array $contactIds): array
    {
        $contactIds = array_values(array_filter($contactIds, static fn (string $id): bool => $id !== ''));

        if ($contactIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($contactIds as $index => $id) {
            $placeholders[] = ':c' . $index;
            $params['c' . $index] = $id;
        }

        $rows = Database::all(
            'SELECT id, company, user_id, ghl_contact_id, status FROM leads
             WHERE ghl_contact_id IN (' . implode(',', $placeholders) . ')',
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['ghl_contact_id']] = $row;
        }

        return $out;
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

        // Leads nobody has opened yet. The notice on the dashboard links
        // straight here, so the filter has to exist for the link to land
        // somewhere useful rather than on an unfiltered list.
        if (!empty($filters['unopened'])) {
            $where[] = 'l.opened_at IS NULL';
        }

        if (!empty($filters['archived_only'])) {
            $where[] = 'l.archived_at IS NOT NULL';
        } elseif (empty($filters['include_archived'])) {
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

        // A disposition is the most useful automation trigger there is —
        // "booked a meeting" should be able to start a sequence on its own.
        // Re-read so the rule sees the status that was just written.
        $updated = self::find($leadId);
        if ($updated !== null) {
            Automations::apply('status', $updated, $actorId);
        }

        return true;
    }

    /**
     * Record that somebody did something to this lead.
     *
     * Every one of these is kept — that is the point of logging an action
     * rather than setting a field. The lead also carries the most recent one,
     * copied onto the row itself, so the list can show "Called · 2d" for three
     * hundred leads without asking the activity table three hundred times.
     * That copy is derived data and the activity rows remain the record.
     *
     * Logging an action deliberately does NOT change the disposition. Having
     * called someone is not the same as having reached them, and quietly
     * moving a lead to Contacted because a voicemail was logged would put
     * words in the caller's mouth.
     */
    public static function logAction(int $leadId, string $action, ?string $note, ?int $actorId): bool
    {
        if (!array_key_exists($action, self::ACTIONS)) {
            return false;
        }

        $note = trim((string) $note);
        $now = Clock::now();

        Database::insert('activities', [
            'lead_id' => $leadId,
            'user_id' => $actorId,
            'type' => 'action',
            'action' => $action,
            'body' => $note === ''
                ? self::actionLabel($action)
                : self::actionLabel($action) . ' — ' . mb_substr($note, 0, 3000),
            'created_at' => $now,
        ]);

        Database::update('leads', [
            'last_action' => $action,
            'last_action_at' => $now,
            'updated_at' => $now,
        ], ['id' => $leadId]);

        return true;
    }

    /**
     * How long ago, in the shortest form that is still honest.
     *
     * "2d" beside a lead is read at a glance; a timestamp is not. Today reads
     * as "today" rather than "0d", because a zero that means "just now" is the
     * sort of thing that gets misread as "never".
     */
    public static function sinceLabel(?string $when): string
    {
        $when = trim((string) $when);

        if ($when === '') {
            return '';
        }

        $then = strtotime($when);

        if ($then === false) {
            return '';
        }

        $days = (int) floor((strtotime(Clock::now()) - $then) / 86400);

        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'yesterday',
            $days < 7 => $days . 'd',
            $days < 70 => (int) round($days / 7) . 'w',
            default => (int) round($days / 30) . 'mo',
        };
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

    /**
     * Remove a lead and its activity trail for good.
     *
     * Archiving is the reversible option and the one the screens lead with.
     * This is for rows that should never have existed — a bad import, the same
     * company under a second spelling, a test row. There is no undo, so every
     * caller confirms first.
     *
     * The activity rows go too. Nothing enforces the foreign key on SQLite by
     * default, so leaving them would strand them against an id that is free to
     * be handed to a different lead later.
     */
    public static function delete(int $leadId): bool
    {
        return (bool) Database::transaction(static function () use ($leadId): bool {
            Database::run('DELETE FROM activities WHERE lead_id = :id', ['id' => $leadId]);

            return Database::run('DELETE FROM leads WHERE id = :id', ['id' => $leadId])->rowCount() > 0;
        });
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

    /**
     * Write contact fields on an existing lead. Whitelisted rather than
     * accepting whatever is handed in — this is fed from a form, and company,
     * company_key, user_id and fit_score must never be editable this way.
     *
     * @param array<string, string> $fields
     */
    public static function updateFields(int $leadId, array $fields): void
    {
        $allowed = [
            'website', 'decision_maker', 'title', 'email', 'email_confidence',
            'phone', 'direct_phone', 'linkedin',
        ];

        $update = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $update[$column] = mb_substr(trim((string) $fields[$column]), 0, 190);
            }
        }

        if ($update === []) {
            return;
        }

        $update['updated_at'] = Clock::now();
        Database::update('leads', $update, ['id' => $leadId]);
    }

    /**
     * A dig runs after the response has been sent, so its state lives on the
     * lead rather than in the request.
     *
     * running → the browser polls
     * done    → findings are waiting to be accepted
     * failed  → dig_message says why
     * null    → nothing in flight, show the button
     */
    public static function startDig(int $leadId): void
    {
        Database::update('leads', [
            'dig_status' => 'running',
            'dig_result' => null,
            'dig_message' => null,
            'dig_at' => Clock::now(),
        ], ['id' => $leadId]);
    }

    /** @param array<string, mixed> $findings */
    public static function finishDig(int $leadId, bool $ok, array $findings, string $message): void
    {
        Database::update('leads', [
            'dig_status' => $ok ? 'done' : 'failed',
            'dig_result' => $ok ? json_encode($findings, JSON_UNESCAPED_SLASHES) : null,
            'dig_message' => mb_substr($message, 0, 2000),
            'dig_at' => Clock::now(),
        ], ['id' => $leadId]);
    }

    public static function clearDig(int $leadId): void
    {
        Database::update('leads', [
            'dig_status' => null,
            'dig_result' => null,
            'dig_message' => null,
            'dig_at' => null,
        ], ['id' => $leadId]);
    }

    /**
     * Read the dig state, decoding the stored findings.
     *
     * A run that started more than ten minutes ago is reported as failed: the
     * worker process died without getting to write its result, and leaving the
     * page polling forever is worse than saying so.
     *
     * @param array<string, mixed> $lead
     * @return array{status: string|null, findings: array<string, mixed>|null, message: string|null}
     */
    public static function digState(array $lead): array
    {
        $status = $lead['dig_status'] ?? null;
        $status = is_string($status) && $status !== '' ? $status : null;

        if ($status === 'running') {
            $started = strtotime((string) ($lead['dig_at'] ?? ''));
            if ($started !== false && (time() - $started) > 600) {
                return [
                    'status' => 'failed',
                    'findings' => null,
                    'message' => 'The dig stopped without finishing. Try it again.',
                ];
            }
        }

        $findings = null;
        if ($status === 'done' && is_string($lead['dig_result'] ?? null)) {
            $decoded = json_decode((string) $lead['dig_result'], true);
            $findings = is_array($decoded) ? $decoded : null;
        }

        return [
            'status' => $status,
            'findings' => $findings,
            'message' => is_string($lead['dig_message'] ?? null) ? (string) $lead['dig_message'] : null,
        ];
    }

    /**
     * Correct the address on a lead, and say so on the timeline.
     *
     * Its own method rather than a Database::update at the call site because
     * the contact_key is derived from the email: change one without the other
     * and de-duplication starts matching on an address the lead no longer has.
     */
    public static function setEmail(int $leadId, string $email, string $confidence, ?int $actorId = null): void
    {
        $lead = self::find($leadId);

        if ($lead === null) {
            return;
        }

        $was = trim((string) ($lead['email'] ?? ''));
        $updated = array_merge($lead, ['email' => $email]);

        Database::update('leads', [
            'email' => mb_substr($email, 0, 190),
            'email_confidence' => in_array($confidence, ['verified', 'high', 'pattern'], true) ? $confidence : 'verified',
            'contact_key' => self::contactKey($updated),
            'updated_at' => Clock::now(),
        ], ['id' => $leadId]);

        self::addActivity(
            $leadId,
            $actorId,
            'note',
            $was === ''
                ? 'Address set to ' . $email
                : 'Address corrected from ' . $was . ' to ' . $email
        );
    }

    /**
     * Note that the owner has looked at this lead.
     *
     * Only the owner: an admin reading over Billy's shoulder does not mean
     * Billy has worked it, and the whole point of the count is to say whether
     * the person it was found for has seen it. Set once and left alone — the
     * question is "has anyone been here", not "when were they last".
     */
    public static function markOpened(int $leadId, int $viewerId): void
    {
        Database::run(
            'UPDATE leads SET opened_at = :now
             WHERE id = :id AND user_id = :viewer AND opened_at IS NULL',
            ['now' => Clock::now(), 'id' => $leadId, 'viewer' => $viewerId]
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
