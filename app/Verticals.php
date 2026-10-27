<?php

declare(strict_types=1);

namespace Prospector;

/**
 * Canonical verticals, and the job of getting free text into one.
 *
 * A lead's vertical arrives as whatever the research wrote that morning —
 * "Tribal gaming", "Casino & Resort", "Tribal casino and hotel", "Gaming &
 * Hospitality". All of those are one thing to the person working the list, but
 * the filter dropdown is built from DISTINCT vertical, so fifteen spellings
 * become fifteen entries and the filter stops being useful at exactly the
 * point there are enough leads to need it.
 *
 * So the vertical column holds one of the loop's own qualifying verticals, and
 * the words that arrived are kept in vertical_detail when they said more. The
 * loop specification already names these — this is that table in code.
 *
 * **An unrecognised vertical is left exactly as it came.** Bucketing is only
 * worth doing when it is right; a lead filed under the wrong heading is harder
 * to find than one filed under an odd heading, because nobody thinks to look.
 */
final class Verticals
{
    /**
     * Rules are tried in order and the first match wins, so the specific ones
     * come before the general. The patterns are matched against a lowercased,
     * punctuation-flattened copy of the value, not the raw string.
     *
     * Most entries are stems with a trailing `\w*`, because the research writes
     * "Casino" one day and "Casinos" the next, and "agricultur" has to reach
     * both "agriculture" and "agricultural". The ones that end in `\b` do so on
     * purpose: "ag" must not match "agency".
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const RULES = [
        'client' => [
            // Before Ag, which would otherwise take "farm" out of "farm and
            // ranch store" — that is a retailer, and the loop files it as one.
            ['Retail', '/\b(farm (and|&) ranch|feed (and|&) seed)\b.*\b(store|retail|supply|supplies)/'],

            // Casino before Healthcare so "casino resort and spa" does not get
            // pulled in by a wellness word. Nothing here relies on "tribal"
            // alone, which is just as likely to be a clinic or a college.
            ['Casino', '/\b(casino\w*|racino\w*|gaming|gambling|slots|bingo|sportsbook|player development)/'],

            ['Healthcare', '/\b(health\w*|hospital\w*|clinic\w*|medical|medicine|dental|dentist\w*|orthoped\w*|cardio\w*|oncolog\w*|surgic\w*|surgery|physician\w*|pharmac\w*|behavioral|senior living|assisted living|nursing|hospice|home care|urgent care|wellness)/'],

            ['Higher Ed', '/\b(college\w*|universit\w*|higher ed\w*|campus\w*|technical institute|enrollment|admissions|academ\w*)/'],

            ['Ag', '/\b(ag\b|agri\w*|agronom\w*|farm\w*|seed\w*|crop\w*|grain\w*|livestock|cattle|dairy|ranch\w*|co-?op\w*|elevator\w*|irrigation|implement\w*)/'],

            ['Retail', '/\b(retail\w*|grocer\w*|supermarket\w*|apparel|clothing|sporting goods|convenience|c-?store\w*|hardware|department store|dealership\w*|store\w*|shop\w*)/'],
        ],
    ];

    /**
     * The verticals a loop files leads under, for anything that wants to offer
     * them as a list rather than discover them from the data.
     *
     * @return list<string>
     */
    public static function forLoop(string $loop): array
    {
        $out = [];

        foreach (self::RULES[$loop] ?? [] as [$canonical, $_]) {
            if (!in_array($canonical, $out, true)) {
                $out[] = $canonical;
            }
        }

        sort($out);

        return $out;
    }

    /** Does this loop have a canonical set at all? */
    public static function manages(string $loop): bool
    {
        return isset(self::RULES[$loop]);
    }

    /**
     * The heading this value belongs under, or the value itself when none of
     * them fit.
     */
    public static function canonical(string $raw, string $loop): string
    {
        $raw = trim($raw);

        if ($raw === '' || !isset(self::RULES[$loop])) {
            return $raw;
        }

        // "Casino/Resort", "Casino & Resort" and "Casino - Resort" are the same
        // sentence as far as a word-boundary match is concerned, once the
        // punctuation between the words is a space.
        $needle = strtolower($raw);
        $needle = preg_replace('/[^a-z0-9&\- ]+/', ' ', $needle) ?? $needle;
        $needle = trim(preg_replace('/\s+/', ' ', $needle) ?? $needle);

        foreach (self::RULES[$loop] as [$canonical, $pattern]) {
            if (preg_match($pattern, $needle) === 1) {
                return $canonical;
            }
        }

        return $raw;
    }

    /**
     * What to keep in vertical_detail: the words that arrived, when they say
     * more than the heading does.
     *
     * "Tribal gaming" under Casino is worth keeping — it is the difference
     * between two quite different sales conversations. "Casino" under Casino is
     * not, and neither is "Casinos".
     */
    public static function detail(string $raw, string $canonical): string
    {
        $raw = trim($raw);

        if ($raw === '' || $canonical === '') {
            return '';
        }

        $flat = static fn (string $s): string => trim(preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '');

        // Same word, or the plural of it.
        if ($flat($raw) === $flat($canonical) || $flat($raw) === $flat($canonical) . 's') {
            return '';
        }

        return $raw;
    }
}
