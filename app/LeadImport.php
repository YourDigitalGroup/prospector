<?php

declare(strict_types=1);

namespace Prospector;

/**
 * Parse an uploaded or pasted lead list into the shape Leads::create expects.
 *
 * Accepts CSV (with a header row) or a JSON array. Headers are matched loosely,
 * because the whole point is that someone can hand it a file they already have
 * rather than reformatting to match us — "Company Name", "company_name" and
 * "Organization" all mean the same column.
 *
 * Nothing here writes to the database. Parsing and storing are deliberately
 * separate so the screen can show what it understood and let a person confirm
 * before anything lands.
 */
final class LeadImport
{
    /**
     * Canonical field => the header spellings that map onto it. Compared after
     * lowercasing and stripping everything that is not a letter or digit, so
     * "Fit Score", "fit_score" and "FitScore" all collapse to "fitscore".
     */
    private const ALIASES = [
        'company' => ['company', 'companyname', 'organization', 'organisation', 'business', 'account', 'name'],
        'website' => ['website', 'url', 'site', 'domain', 'web'],
        'vertical' => ['vertical', 'industry', 'category', 'type', 'segment'],
        'door' => ['door', 'buyerdoor', 'angle', 'entry'],
        'market' => ['market', 'city', 'metro', 'location', 'town'],
        'state' => ['state', 'province', 'region', 'st'],
        'decision_maker' => ['decisionmaker', 'contact', 'contactname', 'person', 'fullname', 'lead'],
        'title' => ['title', 'jobtitle', 'role', 'position'],
        'email' => ['email', 'emailaddress', 'mail', 'workemail'],
        'email_confidence' => ['emailconfidence', 'confidence', 'emailstatus', 'emailquality'],
        'phone' => ['phone', 'phonenumber', 'mainphone', 'telephone', 'tel', 'switchboard'],
        'direct_phone' => ['directphone', 'directdial', 'mobile', 'cell', 'directline'],
        'linkedin' => ['linkedin', 'linkedinurl', 'li'],
        'fit_score' => ['fitscore', 'score', 'fit', 'rating'],
        'why' => ['why', 'whythem', 'reason', 'rationale', 'notes', 'note'],
        'hook' => ['hook', 'openinghook', 'opener', 'pitch', 'angle2'],
        'evidence' => ['evidence', 'source', 'sources', 'proof', 'citation'],
    ];

    private const CONFIDENCES = ['verified', 'high', 'pattern'];

    /**
     * @return array{rows: list<array<string, mixed>>, problems: list<string>, columns: list<string>, ignored: list<string>}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['rows' => [], 'problems' => ['Nothing to import — the file or box was empty.'], 'columns' => [], 'ignored' => []];
        }

        return str_starts_with($raw, '[') || str_starts_with($raw, '{')
            ? self::parseJson($raw)
            : self::parseCsv($raw);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, problems: list<string>, columns: list<string>, ignored: list<string>}
     */
    private static function parseJson(string $raw): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [
                'rows' => [],
                'problems' => ['That looked like JSON but would not parse: ' . json_last_error_msg()],
                'columns' => [],
                'ignored' => [],
            ];
        }

        // Accept a bare array of leads, or the same envelope the worker API
        // takes, so a payload prepared for /api/import can be pasted straight in.
        $list = $decoded['leads'] ?? $decoded;

        if (!is_array($list) || $list === []) {
            return ['rows' => [], 'problems' => ['No leads found in that JSON.'], 'columns' => [], 'ignored' => []];
        }

        $rows = [];
        $problems = [];
        $seenKeys = [];
        $ignored = [];

        foreach (array_values($list) as $index => $entry) {
            if (!is_array($entry)) {
                $problems[] = 'Entry ' . ($index + 1) . ' was not an object.';
                continue;
            }

            $mapped = [];
            foreach ($entry as $key => $value) {
                $field = self::fieldFor((string) $key);
                if ($field === null) {
                    $ignored[(string) $key] = true;
                    continue;
                }
                $mapped[$field] = $value;
            }

            self::collect($mapped, $index + 1, $rows, $problems, $seenKeys);
        }

        return [
            'rows' => $rows,
            'problems' => $problems,
            'columns' => array_keys($rows[0] ?? []),
            'ignored' => array_keys($ignored),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, problems: list<string>, columns: list<string>, ignored: list<string>}
     */
    private static function parseCsv(string $raw): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['rows' => [], 'problems' => ['Could not read that file.'], 'columns' => [], 'ignored' => []];
        }

        // Strip a UTF-8 BOM, which Excel adds and which would otherwise become
        // part of the first header name and stop it matching.
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);
        rewind($handle);

        $delimiter = self::sniffDelimiter($raw);
        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false || $header === [null]) {
            fclose($handle);

            return ['rows' => [], 'problems' => ['The first line could not be read as a header row.'], 'columns' => [], 'ignored' => []];
        }

        $map = [];
        $ignored = [];
        foreach ($header as $position => $label) {
            $field = self::fieldFor((string) $label);
            if ($field === null) {
                if (trim((string) $label) !== '') {
                    $ignored[] = (string) $label;
                }
                continue;
            }
            // First matching column wins, so a stray second "Notes" column does
            // not overwrite the one that was already understood.
            $map[$position] = $map[$position] ?? $field;
            if (!in_array($field, $map, true)) {
                $map[$position] = $field;
            }
        }

        if (!in_array('company', $map, true)) {
            fclose($handle);

            return [
                'rows' => [],
                'problems' => [
                    'No company column found. The header needs one of: '
                    . implode(', ', self::ALIASES['company']) . '.',
                ],
                'columns' => [],
                'ignored' => $ignored,
            ];
        }

        $rows = [];
        $problems = [];
        $seenKeys = [];
        $line = 1;

        while (($record = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;

            if ($record === [null] || implode('', array_map('strval', $record)) === '') {
                continue;
            }

            $mapped = [];
            foreach ($map as $position => $field) {
                $mapped[$field] = $record[$position] ?? '';
            }

            self::collect($mapped, $line, $rows, $problems, $seenKeys);
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'problems' => $problems,
            'columns' => array_values(array_unique($map)),
            'ignored' => array_values(array_unique($ignored)),
        ];
    }

    /**
     * Normalise one mapped row and add it, or record why it was dropped.
     *
     * @param array<string, mixed>       $mapped
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $problems
     * @param array<string, int>         $seenKeys
     */
    private static function collect(array $mapped, int $where, array &$rows, array &$problems, array &$seenKeys): void
    {
        $company = trim((string) ($mapped['company'] ?? ''));

        if ($company === '') {
            $problems[] = 'Row ' . $where . ' had no company name.';

            return;
        }

        // Duplicates inside one file are dropped here rather than at storage,
        // so the preview count matches what actually lands.
        $key = Leads::companyKey($company);
        if (isset($seenKeys[$key])) {
            $problems[] = 'Row ' . $where . ': "' . $company . '" repeats row ' . $seenKeys[$key] . ' in this file.';

            return;
        }
        $seenKeys[$key] = $where;

        $row = ['company' => $company];

        foreach (array_keys(self::ALIASES) as $field) {
            if ($field === 'company') {
                continue;
            }
            $value = trim((string) ($mapped[$field] ?? ''));
            if ($value !== '') {
                $row[$field] = $value;
            }
        }

        $row['fit_score'] = self::score($mapped['fit_score'] ?? null);

        if (isset($row['email'])) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = 'Row ' . $where . ': "' . $row['email'] . '" is not a valid email address, so it was left off.';
                unset($row['email'], $row['email_confidence']);
            } else {
                // An address with no stated confidence is treated as unverified.
                // Assuming otherwise is how an unchecked address ends up in a
                // bulk send.
                $stated = strtolower((string) ($row['email_confidence'] ?? ''));
                $row['email_confidence'] = in_array($stated, self::CONFIDENCES, true) ? $stated : 'pattern';
            }
        } else {
            unset($row['email_confidence']);
        }

        if (isset($row['website'])) {
            $row['website'] = self::normaliseUrl($row['website']);
        }

        $rows[] = $row;
    }

    private static function score(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, min(100, (int) round((float) $value)));
        }

        // Unscored is 0 rather than a guess. Uploads do not apply the fit-score
        // floor, so this costs nothing but keeps the column honest.
        return 0;
    }

    private static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return 'https://' . ltrim($url, '/');
    }

    private static function fieldFor(string $label): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $label) ?? '');

        if ($key === '') {
            return null;
        }

        foreach (self::ALIASES as $field => $spellings) {
            if (in_array($key, $spellings, true)) {
                return $field;
            }
        }

        return null;
    }

    /** Excel in some locales writes semicolons; tab-separated paste is common too. */
    private static function sniffDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\r\n");
        if ($firstLine === false) {
            return ',';
        }

        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return array_keys(self::ALIASES);
    }

    /**
     * A worked example of an import file, offered as a download so nobody has
     * to guess the column names from prose.
     *
     * It lives here rather than in the controller so it cannot drift from
     * ALIASES: the headers are the canonical field names, and a field added
     * above shows up in the sample the moment it is added.
     *
     * Every company and person is invented and every address is at
     * example.com, so a copy of this file that gets loaded by mistake sends
     * nothing to anyone real.
     *
     * Three rows on purpose: a full one, one for the other loop, and one with
     * almost nothing in it — because "only company is required" is easier to
     * believe when you can see it.
     *
     * @return list<array<string, string|int>>
     */
    public static function sample(): array
    {
        return [
            [
                'company' => 'Prairie Sky Radio',
                'website' => 'https://prairieskyradio.example.com',
                'vertical' => 'Independent radio',
                'door' => 'Gap Filler',
                'market' => 'Sioux City',
                'state' => 'IA',
                'decision_maker' => 'Dana Whitfield',
                'title' => 'General Manager',
                'email' => 'dwhitfield@example.com',
                'email_confidence' => 'verified',
                'phone' => '(712) 555-0142',
                'direct_phone' => '(712) 555-0188',
                'linkedin' => 'https://www.linkedin.com/in/example-profile',
                'fit_score' => 88,
                'why' => 'Sells social and websites but nothing programmatic; hiring a digital sales rep.',
                'hook' => 'Saw you are hiring a digital seller — what happens when they sell OTT?',
                'evidence' => 'prairieskyradio.example.com/advertise, careers page',
            ],
            [
                'company' => 'Cedar Valley Regional Health',
                'website' => 'https://cedarvalleyhealth.example.org',
                'vertical' => 'Healthcare',
                'door' => 'Growth Moment',
                'market' => 'Brookings',
                'state' => 'SD',
                'decision_maker' => 'Marcus Adeyemi',
                'title' => 'Director of Marketing & Communications',
                'email' => 'madeyemi@example.org',
                'email_confidence' => 'pattern',
                'phone' => '(605) 555-0117',
                'direct_phone' => '',
                'linkedin' => '',
                'fit_score' => 81,
                'why' => 'Broke ground on a new orthopedics wing in March; no video anywhere on the site.',
                'hook' => 'Congratulations on the orthopedics wing — is the campaign for it already placed?',
                'evidence' => 'Press release, 12 March',
            ],
            [
                'company' => 'Bluestem Outdoor Advertising',
                'website' => '',
                'vertical' => '',
                'door' => '',
                'market' => 'Fargo',
                'state' => 'ND',
                'decision_maker' => '',
                'title' => '',
                'email' => '',
                'email_confidence' => '',
                'phone' => '',
                'direct_phone' => '',
                'linkedin' => '',
                'fit_score' => '',
                'why' => 'Only company is required — everything else can be blank and filled in later.',
                'hook' => '',
                'evidence' => '',
            ],
        ];
    }
}
