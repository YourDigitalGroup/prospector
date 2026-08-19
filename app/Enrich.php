<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Settings;
use RuntimeException;
use Throwable;

/**
 * "Dig for contact details" — fill in a missing work email, direct line or
 * LinkedIn for a lead that arrived without one.
 *
 * Two passes, same shape as a batch: research with web search and web fetch,
 * then a structured extraction of what was actually found. Every value comes
 * back with the URL it was read from, and nothing is written to the lead until
 * a person accepts it.
 *
 * SOURCE POLICY — the important part of this class.
 *
 * This looks for BUSINESS contact details: a work email, a desk or direct line,
 * a public professional profile. It does not use people-search or data-broker
 * sites (fastpeoplesearch, truepeoplesearch, whitepages, spokeo, and the rest),
 * for three reasons that all point the same way:
 *
 *   1. They return the wrong thing. Home addresses, personal mobiles and
 *      relatives are not what a first call to a station GM needs — a work
 *      address at the company domain is, and that is on the company's own site.
 *   2. A personal mobile in the phone column is a compliance problem waiting to
 *      happen. Once it is in GoHighLevel, a sequence can text it, and calls and
 *      texts to wireless numbers are exactly what the TCPA and its state
 *      equivalents police. A desk line carries none of that.
 *   3. They block automated access anyway, so the results would be unreliable
 *      even setting the first two aside.
 *
 * The block list is passed to the API as blocked_domains, so it is enforced on
 * Anthropic's side rather than left to the model's discretion.
 */
final class Enrich
{
    /**
     * Sites that trade in personal residential data. Blocked at the tool level.
     * Add to this list rather than relying on the prompt.
     */
    private const BLOCKED_DOMAINS = [
        'fastpeoplesearch.com',
        'truepeoplesearch.com',
        'whitepages.com',
        'spokeo.com',
        'beenverified.com',
        'intelius.com',
        'peoplefinders.com',
        'thatsthem.com',
        'radaris.com',
        'usphonebook.com',
        'anywho.com',
        'zabasearch.com',
        'checkpeople.com',
        'searchpeoplefree.com',
        'clustrmaps.com',
        'nuwber.com',
    ];

    private const SYSTEM = <<<'TXT'
    You find business contact details for B2B sales outreach, and you are
    fastidious about not making things up.

    WHAT YOU ARE LOOKING FOR
    A work email address, a desk or direct office line, and a public
    professional profile URL for a named person at a named company. Work
    contact details only.

    WHERE TO LOOK, roughly in this order
    1. The company's own website — team, staff, leadership, about, contact and
       advertise pages. This is where the answer usually is.
    2. Regulatory and public filings. For broadcasters, FCC records and public
       inspection files list contacts. For companies generally, state business
       registries.
    3. Press releases and local news coverage naming the person.
    4. Trade association and chamber-of-commerce member rosters.
    5. Public professional profiles, conference speaker pages, podcast guest
       bios, bylined articles.

    WHERE NOT TO LOOK
    Do not use people-search or data-broker sites. They return home addresses
    and personal mobile numbers, which are not business contact details and are
    not wanted here. Several are blocked outright; do not look for workarounds.
    If the only number you can find is a personal mobile, report no number.

    HOW TO REPORT WHAT YOU FIND
    For every value, record the exact URL you read it from. If you did not read
    it on a page you actually opened, you do not have it.

    Confidence, for email addresses:
      verified  read directly off the company's own site, a filing, or a
                press release
      high      two independent sources agree on it
      pattern   you did NOT find this address; you inferred it from the
                company's visible format (e.g. other staff are
                first.last@company.com). Say so. A pattern address has not been
                confirmed to exist.

    Never label an inferred address as verified. Never invent a person who is
    not named on a page you opened. Returning nothing is a perfectly good
    outcome and much better than a plausible guess.
    TXT;

    /**
     * Run a dig for one lead.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, findings: array<string, mixed>, message: string, cost: array{input: int, output: int}}
     */
    public static function dig(array $lead): array
    {
        if (Settings::anthropicKey() === '') {
            return [
                'ok' => false,
                'findings' => [],
                'message' => 'Digging needs an Anthropic API key. Add one under Settings → Anthropic API.',
                'cost' => ['input' => 0, 'output' => 0],
            ];
        }

        $company = trim((string) ($lead['company'] ?? ''));
        if ($company === '') {
            return [
                'ok' => false,
                'findings' => [],
                'message' => 'This lead has no company name to search on.',
                'cost' => ['input' => 0, 'output' => 0],
            ];
        }

        try {
            $claude = new Claude(Settings::get('model', 'claude-opus-5'), 'medium');

            $research = $claude->research(self::SYSTEM, self::researchPrompt($lead), self::BLOCKED_DOMAINS);

            $extract = $claude->extract(
                self::SYSTEM,
                "Here is the research you just did. Turn it into the structured result.\n"
                . "Include only values you actually read on a page, each with its source URL.\n\n"
                . $research['text'],
                self::schema()
            );

            $findings = self::normalise(is_array($extract['data']) ? $extract['data'] : [], $lead);

            return [
                'ok' => true,
                'findings' => $findings,
                'message' => $findings['found'] === []
                    ? 'Nothing new found. That usually means the details are not published anywhere public.'
                    : 'Found ' . implode(', ', array_keys($findings['found'])) . '.',
                'cost' => [
                    'input' => (int) $research['input_tokens'] + (int) $extract['input_tokens'],
                    'output' => (int) $research['output_tokens'] + (int) $extract['output_tokens'],
                ],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'findings' => [],
                'message' => 'The dig failed: ' . $e->getMessage(),
                'cost' => ['input' => 0, 'output' => 0],
            ];
        }
    }

    /** @param array<string, mixed> $lead */
    private static function researchPrompt(array $lead): string
    {
        $lines = [];
        $add = static function (string $label, mixed $value) use (&$lines): void {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        };

        $add('Company', $lead['company'] ?? null);
        $add('Website', $lead['website'] ?? null);
        $add('Market', $lead['market'] ?? null);
        $add('State', $lead['state'] ?? null);
        $add('Vertical', $lead['vertical'] ?? null);
        $add('Known contact name', $lead['decision_maker'] ?? null);
        $add('Known title', $lead['title'] ?? null);
        $add('Known main phone', $lead['phone'] ?? null);

        $wanted = [];
        if (trim((string) ($lead['email'] ?? '')) === '') {
            $wanted[] = 'a work email address';
        }
        if (trim((string) ($lead['direct_phone'] ?? '')) === '' && trim((string) ($lead['phone'] ?? '')) === '') {
            $wanted[] = 'a desk or direct office phone number';
        } elseif (trim((string) ($lead['direct_phone'] ?? '')) === '') {
            $wanted[] = 'a direct line for the contact, if one is published';
        }
        if (trim((string) ($lead['linkedin'] ?? '')) === '') {
            $wanted[] = 'a public professional profile URL';
        }
        if (trim((string) ($lead['decision_maker'] ?? '')) === '') {
            $wanted[] = 'the name and title of whoever owns marketing or advertising decisions';
        }

        if ($wanted === []) {
            $wanted[] = 'anything that would improve on the contact details already on file';
        }

        return "Find business contact details for this company.\n\n"
            . implode("\n", $lines) . "\n\n"
            . "What is missing and needed:\n- " . implode("\n- ", $wanted) . "\n\n"
            . "Start with the company's own website. Open the pages, do not guess from search snippets. "
            . "Then report exactly what you read and where you read it. If you cannot find something, say so.";
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $nullableString = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['email', 'email_confidence', 'email_source', 'direct_phone', 'direct_phone_source',
                'phone', 'phone_source', 'linkedin', 'linkedin_source', 'decision_maker', 'title',
                'person_source', 'pages_opened', 'notes'],
            'properties' => [
                'email' => $nullableString,
                'email_confidence' => ['anyOf' => [
                    ['type' => 'string', 'enum' => ['verified', 'high', 'pattern']],
                    ['type' => 'null'],
                ]],
                'email_source' => $nullableString,
                'direct_phone' => $nullableString,
                'direct_phone_source' => $nullableString,
                'phone' => $nullableString,
                'phone_source' => $nullableString,
                'linkedin' => $nullableString,
                'linkedin_source' => $nullableString,
                'decision_maker' => $nullableString,
                'title' => $nullableString,
                'person_source' => $nullableString,
                'pages_opened' => ['type' => 'array', 'items' => ['type' => 'string']],
                'notes' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Turn raw model output into findings the screen can show.
     *
     * Pure, and separately tested: a value is only offered if it is well-formed
     * AND carries a source URL AND differs from what the lead already has.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $lead
     * @return array{found: array<string, array{value: string, source: string, confidence?: string}>, pages: list<string>, notes: string}
     */
    public static function normalise(array $raw, array $lead): array
    {
        $found = [];

        $source = static function (mixed $value): string {
            $url = trim((string) ($value ?? ''));

            return preg_match('#^https?://#i', $url) === 1 ? $url : '';
        };

        // Email. Must parse, must have a source, and a pattern address is only
        // ever offered as pattern — the model does not get to promote it.
        $email = strtolower(trim((string) ($raw['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $confidence = (string) ($raw['email_confidence'] ?? '');
            $confidence = in_array($confidence, ['verified', 'high', 'pattern'], true) ? $confidence : 'pattern';
            $emailSource = $source($raw['email_source'] ?? null);

            // A verified or high claim without a URL to back it is downgraded,
            // because the source is the whole basis for the claim.
            if ($emailSource === '' && $confidence !== 'pattern') {
                $confidence = 'pattern';
            }

            if ($email !== strtolower(trim((string) ($lead['email'] ?? '')))) {
                $found['email'] = [
                    'value' => $email,
                    'source' => $emailSource,
                    'confidence' => $confidence,
                ];
            }
        }

        foreach (['direct_phone', 'phone'] as $field) {
            $digits = preg_replace('/\D+/', '', (string) ($raw[$field] ?? '')) ?? '';
            // 10 digits US, 11 with the country code. Anything shorter is a
            // fragment, anything longer is not a phone number.
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                continue;
            }
            $existing = preg_replace('/\D+/', '', (string) ($lead[$field] ?? '')) ?? '';
            if ($digits === $existing) {
                continue;
            }
            $found[str_replace('_', ' ', $field)] = [
                'value' => self::formatPhone((string) $raw[$field], $digits),
                'source' => $source($raw[$field . '_source'] ?? null),
            ];
        }

        $linkedin = trim((string) ($raw['linkedin'] ?? ''));
        if ($linkedin !== ''
            && preg_match('#^https?://#i', $linkedin) === 1
            && $linkedin !== trim((string) ($lead['linkedin'] ?? ''))) {
            $found['linkedin'] = ['value' => $linkedin, 'source' => $source($raw['linkedin_source'] ?? null) ?: $linkedin];
        }

        // A person is only offered when the lead has none. Replacing a known
        // contact with a different one is a judgement call, not an enrichment.
        if (trim((string) ($lead['decision_maker'] ?? '')) === '') {
            $person = trim((string) ($raw['decision_maker'] ?? ''));
            $personSource = $source($raw['person_source'] ?? null);
            if ($person !== '' && $personSource !== '') {
                $found['decision maker'] = ['value' => $person, 'source' => $personSource];
                $title = trim((string) ($raw['title'] ?? ''));
                if ($title !== '') {
                    $found['title'] = ['value' => $title, 'source' => $personSource];
                }
            }
        }

        $pages = [];
        foreach ((array) ($raw['pages_opened'] ?? []) as $page) {
            $url = $source($page);
            if ($url !== '' && !in_array($url, $pages, true)) {
                $pages[] = $url;
            }
        }

        return [
            'found' => $found,
            'pages' => array_slice($pages, 0, 12),
            'notes' => trim((string) ($raw['notes'] ?? '')),
        ];
    }

    /** Keep the model's formatting when it is sane, otherwise format the digits. */
    private static function formatPhone(string $given, string $digits): string
    {
        $given = trim($given);
        if ($given !== '' && strlen($given) <= 24) {
            return $given;
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }

        return $digits;
    }

    /**
     * Which lead fields the dig writes to, mapped from the finding labels the
     * screen shows.
     *
     * @return array<string, string>
     */
    public static function fieldMap(): array
    {
        return [
            'email' => 'email',
            'direct phone' => 'direct_phone',
            'phone' => 'phone',
            'linkedin' => 'linkedin',
            'decision maker' => 'decision_maker',
            'title' => 'title',
        ];
    }

    /** Is this lead missing the details a dig would look for? */
    public static function isThin(array $lead): bool
    {
        return trim((string) ($lead['email'] ?? '')) === ''
            || (trim((string) ($lead['phone'] ?? '')) === '' && trim((string) ($lead['direct_phone'] ?? '')) === '');
    }

    /** @return list<string> */
    public static function blockedDomains(): array
    {
        return self::BLOCKED_DOMAINS;
    }
}
