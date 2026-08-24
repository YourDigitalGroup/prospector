<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Settings;
use RuntimeException;
use Throwable;

/**
 * Writes outreach email copy for a lead from the qualification the batch
 * already produced.
 *
 * No web search here. Everything this needs — why the lead qualified, which
 * buyer door fits, what the opening hook is, what evidence was read — is
 * already on the lead row, and going back to the web would only invite the
 * model to introduce facts nobody checked. That also makes this cheap: one
 * call per lead writes all six steps, rather than six calls or a research
 * turn whose fetched pages get re-billed on every continuation.
 */
final class Outreach
{
    /**
     * The cadence. Day offsets from the first send, and what each step is for.
     *
     * Six steps over a month, front-loaded then spaced out. The purposes are
     * deliberately different from each other: six variations on "just checking
     * in" is what makes a sequence read as automated.
     */
    public const CADENCE = [
        1 => ['day' => 0, 'purpose' => 'Opener — the specific thing you noticed, one ask'],
        2 => ['day' => 3, 'purpose' => 'Proof — a comparable result in their own vertical'],
        3 => ['day' => 7, 'purpose' => 'Second angle — the same problem from a different side'],
        4 => ['day' => 14, 'purpose' => 'Nudge — three lines, one question, easy to answer'],
        5 => ['day' => 21, 'purpose' => 'Something useful — no ask at all'],
        6 => ['day' => 30, 'purpose' => 'Close the loop — permission to stop'],
    ];

    /** Models offered for writing copy, cheapest first. */
    public const MODELS = ['claude-haiku-4-5', 'claude-sonnet-5', 'claude-opus-5'];

    public static function model(): string
    {
        $model = Settings::get('outreach_model', 'claude-sonnet-5');

        return in_array($model, self::MODELS, true) ? $model : 'claude-sonnet-5';
    }

    public static function steps(): int
    {
        return count(self::CADENCE);
    }

    /**
     * Who the sender is, and what they are actually selling. Billy is selling
     * fulfilment to a reseller; Darren and Sara are selling 44i's own services
     * to an end client. Getting this wrong produces an email that pitches the
     * wrong thing entirely, so it is keyed off the owner's loop rather than
     * guessed from the lead.
     *
     * @param array<string, mixed> $owner
     */
    public static function positioning(array $owner): string
    {
        $name = (string) ($owner['name'] ?? 'the 44i team');

        return match ((string) ($owner['loop'] ?? '')) {
            'partner' => "You are writing as {$name} at 44i Digital (44idigital.com), a whitelabel "
                . "fulfilment partner in Sioux Falls, South Dakota.\n\n"
                . "The reader is a media company or ad agency. They would RESELL digital services to "
                . "their own advertisers under their own brand, and 44i does the work invisibly behind "
                . "them — SEO, Google Ads, programmatic display, OTT/CTV, geofencing, social, websites, "
                . "reputation management. They keep the client relationship and the margin. 44i's name "
                . "never appears in front of their advertisers.\n\n"
                . "Never pitch this as marketing services for the reader's own company. They are not "
                . "the customer for the work — they are the channel. Useful specifics: month-to-month "
                . "with no lock-in, a revenue guarantee for new partners, and a team in Sioux Falls "
                . "who answer their own phones.",

            'home' => "You are writing as {$name} at 44i (44i.com), a full-service marketing agency in "
                . "Sioux Falls, South Dakota.\n\n"
                . "The reader owns or runs a home-related business — a contractor, a specialty trade, a "
                . "design studio, a home retailer, a landscaper. 44i would run their marketing: local "
                . "SEO and Google Business Profile, reviews and reputation, paid search, targeted "
                . "display and geofencing, OTT/CTV, video, websites.\n\n"
                . "These buyers care about LEAD FLOW, not brand. The phone ringing in February. Say "
                . "nothing about brand strategy, positioning or identity. The reader usually owns the "
                . "company, answers their own phone, and has no patience for agency language.",

            default => "You are writing as {$name} at 44i (44i.com), a full-service marketing agency in "
                . "Sioux Falls, South Dakota.\n\n"
                . "The reader is a marketing director, CMO or owner at an end client — a hospital, a "
                . "casino, a college, an ag company, a regional retailer. 44i would become their agency "
                . "of record: video production, websites, SEO, social, targeted display, OTT/CTV, "
                . "digital billboards, reputation management, branding, and radio and TV buying, all "
                . "coordinated under one roof.\n\n"
                . "Useful specifics: Inc. 5000, 5.0-rated, and a free digital analysis as the opening "
                . "offer rather than a pitch.",
        };
    }

    private const SYSTEM = <<<'TXT'
        You write cold outreach email for a small agency's sellers. Your copy gets read on a phone by
        someone who did not ask to hear from you, so it earns its length or it gets deleted.

        Rules, all of them hard:

        - Use only facts given to you in the brief. Never invent a statistic, a client name, a case
          study, a mutual connection, a price, or anything about the reader's business that is not in
          the brief. If you have no proof point, write the email without one.
        - Open by naming the specific thing that was noticed about this company. That specificity is
          the only reason the email works. Never open with "I hope this finds you well", "I came
          across your website", or anything that would read identically to a hundred other companies.
        - One ask per email, and the same ask throughout: a short call. Never stack two.
        - Plain text. No merge tags, no placeholders like [Company] or {{first_name}}, no HTML, no
          bullet lists, no headers, no P.S. Write what will actually be sent.
        - Banned words and phrases: synergy, leverage, solutions, reach out, circle back, touch base,
          in today's landscape, game-changer, unlock, elevate, robust, best-in-class, I hope this
          email finds you well.
        - Sign off with the sender's first name only. No title block, no company footer — the email
          client adds that.
        - Subject lines: under 55 characters, lower-case or sentence case, no emoji, no "Re:" fakery,
          and never a question you already answer in the first line.
        - Vary the shape across the sequence. If two emails in the sequence could be swapped without
          anyone noticing, you have written the same email twice.

        Length: step 1 under 120 words. Steps 2 and 3 under 110. Steps 4, 5 and 6 under 70. Shorter
        is always better than padded.
        TXT;

    /**
     * Write one or more steps for a lead.
     *
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $owner
     * @param list<int>            $steps which cadence steps to write
     * @return array{
     *     ok: bool,
     *     emails: list<array{step: int, subject: string, body: string}>,
     *     message: string,
     *     cost: array<string, mixed>|null
     * }
     */
    public static function write(array $lead, array $owner, array $steps): array
    {
        $steps = array_values(array_filter($steps, static fn (int $s): bool => isset(self::CADENCE[$s])));

        if ($steps === []) {
            return ['ok' => false, 'emails' => [], 'message' => 'No valid cadence steps requested.', 'cost' => null];
        }

        $model = self::model();

        try {
            $claude = new Claude($model, 'low');
            $result = $claude->extract(
                self::SYSTEM . "\n\n=== WHO YOU ARE ===\n\n" . self::positioning($owner),
                self::brief($lead, $owner, $steps),
                self::schema($steps)
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'emails' => [], 'message' => $e->getMessage(), 'cost' => null];
        }

        $emails = self::normalise($result['data'], $steps);

        if ($emails === []) {
            return [
                'ok' => false,
                'emails' => [],
                'message' => 'The model returned nothing usable. Try again, or a different model under Settings.',
                'cost' => null,
            ];
        }

        return [
            'ok' => true,
            'emails' => $emails,
            'message' => count($emails) === 1
                ? 'Wrote the opening email.'
                : 'Wrote ' . count($emails) . ' emails.',
            'cost' => [
                'model' => $model,
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'dollars' => Claude::estimateCost($model, $result['input_tokens'], $result['output_tokens']),
            ],
        ];
    }

    /**
     * Pull the model's output into a clean list, dropping anything that came
     * back empty. Pure, so it can be tested without an API key.
     *
     * @param array<string, mixed> $data
     * @param list<int>            $steps
     * @return list<array{step: int, subject: string, body: string}>
     */
    public static function normalise(array $data, array $steps): array
    {
        $raw = $data['emails'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $wanted = array_flip($steps);
        $emails = [];
        $seen = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $step = (int) ($entry['step'] ?? 0);
            $subject = trim((string) ($entry['subject'] ?? ''));
            $body = trim((string) ($entry['body'] ?? ''));

            // A step nobody asked for, a duplicate, or an empty shell is worse
            // than a gap — it would show up as a real email ready to send.
            if (!isset($wanted[$step]) || isset($seen[$step]) || $subject === '' || $body === '') {
                continue;
            }

            $seen[$step] = true;
            $emails[] = ['step' => $step, 'subject' => $subject, 'body' => $body];
        }

        usort($emails, static fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        return $emails;
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $owner
     * @param list<int>            $steps
     */
    private static function brief(array $lead, array $owner, array $steps): string
    {
        $contact = trim((string) ($lead['decision_maker'] ?? ''));
        $title = trim((string) ($lead['title'] ?? ''));

        $lines = [
            'Company: ' . (string) $lead['company'],
        ];

        foreach ([
            'Website' => 'website',
            'Vertical' => 'vertical',
            'Market' => 'market',
            'State' => 'state',
        ] as $label => $field) {
            $value = trim((string) ($lead[$field] ?? ''));
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        $lines[] = $contact !== ''
            ? 'Writing to: ' . $contact . ($title !== '' ? ', ' . $title : '')
            : 'Writing to: nobody is named. Address the role, not a person, and never invent a name.';

        $door = trim((string) ($lead['door'] ?? ''));
        if ($door !== '') {
            $lines[] = 'Buyer door: ' . $door . ' — the angle every email should come from';
        }

        $why = trim((string) ($lead['why'] ?? ''));
        if ($why !== '') {
            $lines[] = 'Why this company qualified: ' . $why;
        }

        $hook = trim((string) ($lead['hook'] ?? ''));
        if ($hook !== '') {
            $lines[] = 'Opening hook the researcher wrote: ' . $hook
                . ' (use it as the angle for step 1, but write it properly — do not paste it)';
        }

        $evidence = self::evidenceLines($lead);
        if ($evidence !== '') {
            $lines[] = 'Evidence that was actually read: ' . $evidence;
        }

        $requested = [];
        foreach ($steps as $step) {
            $requested[] = 'Step ' . $step . ' (day ' . self::CADENCE[$step]['day'] . '): '
                . self::CADENCE[$step]['purpose'];
        }

        $count = count($steps);

        return "Write " . ($count === 1 ? 'one email' : $count . ' emails') . " for this lead.\n\n"
            . "=== THE LEAD ===\n" . implode("\n", $lines) . "\n\n"
            . "=== THE EMAILS TO WRITE ===\n" . implode("\n", $requested) . "\n\n"
            . ($count > 1
                ? "These go out as a sequence to the same person over a month, so each one has to "
                . "assume the previous ones were read and ignored. Do not restate the opener. Never "
                . "say \"following up\" or \"just checking in\".\n\n"
                : '')
            . "Everything you know about this company is above. If the brief does not support a "
            . "claim, leave the claim out — an email with one honest specific beats one with three "
            . "invented ones.\n\n"
            . "Sign as " . (string) ($owner['name'] ?? '44i') . ".";
    }

    /** @param array<string, mixed> $lead */
    private static function evidenceLines(array $lead): string
    {
        $raw = $lead['evidence'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return trim($raw);
        }

        $parts = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $parts[] = trim($item);
            } elseif (is_array($item)) {
                $text = trim((string) ($item['note'] ?? $item['source'] ?? $item['url'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode(' · ', array_slice($parts, 0, 6));
    }

    /**
     * @param list<int> $steps
     * @return array<string, mixed>
     */
    private static function schema(array $steps): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['emails'],
            'properties' => [
                'emails' => [
                    'type' => 'array',
                    'description' => 'One entry per requested step, in step order.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['step', 'subject', 'body'],
                        'properties' => [
                            'step' => [
                                'type' => 'integer',
                                'description' => 'The cadence step this is, one of: '
                                    . implode(', ', $steps),
                            ],
                            'subject' => [
                                'type' => 'string',
                                'description' => 'Subject line, under 55 characters.',
                            ],
                            'body' => [
                                'type' => 'string',
                                'description' => 'The email body as plain text, with the sign-off. '
                                    . 'Real newlines between paragraphs. No merge tags.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Whether a lead can be emailed at all, and why not when it cannot.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, reason: string}
     */
    public static function deliverability(array $lead): array
    {
        $email = trim((string) ($lead['email'] ?? ''));

        if ($email === '') {
            return ['ok' => false, 'reason' => 'No email address on file — dig for one first.'];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'reason' => 'The address on file is not a valid email address.'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * A `pattern` address was inferred from a company's email format and never
     * confirmed. One deliberate send to one of those is a judgement call the
     * sender can make; a hundred of them at once is how a sending domain gets
     * burned, which is why the mass send treats this as a gate.
     *
     * @param array<string, mixed> $lead
     */
    public static function isUnverified(array $lead): bool
    {
        return strtolower(trim((string) ($lead['email_confidence'] ?? ''))) === 'pattern';
    }
}
