<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Settings;
use RuntimeException;

/**
 * Runs one prospecting batch for one user: assembles the prompt from the loop
 * spec, researches with web search, extracts structured leads, stores them,
 * and emails the brief.
 */
final class Prospector
{
    /**
     * @param array<string, mixed> $user
     * @return array{ok: bool, run_id: int|null, leads: int, skipped: int, message: string}
     */
    public static function runFor(array $user, string $trigger = 'manual', bool $sendEmail = true): array
    {
        $userId = (int) $user['id'];
        $loop = (string) $user['loop'];

        if (!Users::isRunnableLoop($loop)) {
            return self::failure(null, 0, 0, $user['name'] . ' has no prospecting loop assigned.');
        }

        if (Runs::isRunning($userId)) {
            return self::failure(null, 0, 0, 'A batch is already running for ' . $user['name'] . '.');
        }

        $date = Clock::today();
        $vertical = Runs::verticalFor($loop);
        $geography = trim((string) ($user['geography'] ?? '')) !== ''
            ? (string) $user['geography']
            : Runs::geographyFor($loop);

        $batchSize = Settings::int('batch_size', 10);
        $minScore = Settings::int('min_fit_score', 70);
        $effort = Settings::get('effort', 'high');
        $model = Settings::get('model', 'claude-opus-5');

        $claude = new Claude($model, $effort);
        $runId = Runs::start($userId, $loop, $date, $trigger, $vertical, $geography, $claude->model());

        try {
            $exclusions = Leads::sentCompanies($userId, Settings::int('dedupe_days', 365));

            $system = self::systemPrompt($loop);
            $researchPrompt = self::researchPrompt(
                $loop,
                (string) $user['name'],
                $vertical,
                $geography,
                $batchSize,
                $minScore,
                $exclusions
            );

            $research = $claude->research($system, $researchPrompt);

            $extracted = $claude->extract(
                'You convert a prospecting brief into structured rows. Copy values exactly as the '
                . 'brief states them. Never invent a contact detail that is not in the brief. Use '
                . 'null for anything the brief does not provide.',
                "Convert this prospecting brief into structured leads.\n\n"
                . "Rules:\n"
                . "- One entry per company in the brief's lead table or lead list.\n"
                . "- Copy the fit score the brief assigned. Do not recompute it.\n"
                . "- email_confidence must be verified, high, or pattern — use the label the brief\n"
                . "  gives. If the brief gives no email, set email and email_confidence to null.\n"
                . "- Do not include companies the brief rejected, flagged as disqualified, or listed\n"
                . "  only as considered-and-dropped.\n\n"
                . "BRIEF:\n\n" . $research['text'],
                self::leadSchema()
            );

            $payload = $extracted['data'];
            /** @var list<array<string, mixed>> $rawLeads */
            $rawLeads = is_array($payload['leads'] ?? null) ? $payload['leads'] : [];

            $stored = 0;
            $skipped = 0;

            foreach ($rawLeads as $lead) {
                if (!is_array($lead)) {
                    continue;
                }

                if ((int) ($lead['fit_score'] ?? 0) < $minScore) {
                    $skipped++;
                    continue;
                }

                $id = Leads::create($userId, $runId, $lead);
                if ($id > 0) {
                    $stored++;
                    Leads::addActivity($id, null, 'created', 'Delivered by the ' . Runs::loopLabel($loop) . ' batch');
                } else {
                    $skipped++;
                }
            }

            Runs::finish($runId, [
                'status' => $stored > 0 ? 'success' : 'partial',
                'lead_count' => $stored,
                'brief_md' => $research['text'],
                'input_tokens' => $research['input_tokens'] + $extracted['input_tokens'],
                'output_tokens' => $research['output_tokens'] + $extracted['output_tokens'],
            ]);

            $message = $stored . ' ' . ($stored === 1 ? 'lead' : 'leads') . ' delivered';
            if ($skipped > 0) {
                $message .= ', ' . $skipped . ' skipped (duplicate or below the score floor)';
            }

            if ($sendEmail && (int) ($user['daily_email'] ?? 0) === 1) {
                $run = Runs::find($runId);
                if ($run !== null) {
                    $sent = Mailer::sendDailyBrief($user, $run, Leads::forRun($runId));
                    if ($sent['ok']) {
                        Runs::markEmailed($runId);
                        $message .= '. Email sent to ' . $user['email'];
                    } else {
                        $message .= '. Email failed: ' . $sent['message'];
                    }
                }
            }

            return [
                'ok' => true,
                'run_id' => $runId,
                'leads' => $stored,
                'skipped' => $skipped,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            Runs::fail($runId, $e->getMessage());

            return self::failure($runId, 0, 0, $e->getMessage());
        }
    }

    /**
     * Run every scheduled user. Called by cron.
     *
     * @return list<array{user: string, ok: bool, message: string}>
     */
    public static function runScheduled(string $trigger = 'cron', bool $force = false): array
    {
        // With an external worker or manual paste-in selected, Prospector must
        // not reach for the paid API on a schedule — the worker drives instead.
        $engine = Settings::engine();

        if ($engine !== 'api') {
            return [[
                'user' => 'all',
                'ok' => true,
                'message' => $engine === 'worker'
                    ? 'Engine is set to the external worker, which pulls its own assignment. Nothing to do here.'
                    : 'Engine is set to manual paste-in. Nothing runs on a schedule.',
            ]];
        }

        $results = [];

        foreach (Users::scheduled() as $user) {
            $existing = Runs::forUserOnDate((int) $user['id'], Clock::today());

            if (!$force && $existing !== null && in_array((string) $existing['status'], ['success', 'partial', 'running'], true)) {
                $results[] = [
                    'user' => (string) $user['name'],
                    'ok' => true,
                    'message' => 'Already ran today — skipped.',
                ];
                continue;
            }

            $result = self::runFor($user, $trigger);
            $results[] = [
                'user' => (string) $user['name'],
                'ok' => $result['ok'],
                'message' => $result['message'],
            ];
        }

        return $results;
    }

    /** @return array{ok: bool, run_id: int|null, leads: int, skipped: int, message: string} */
    private static function failure(?int $runId, int $leads, int $skipped, string $message): array
    {
        return ['ok' => false, 'run_id' => $runId, 'leads' => $leads, 'skipped' => $skipped, 'message' => $message];
    }

    public static function loopSpec(string $loop): string
    {
        // Whitelisted, not sanitised: $loop reaches here from a database column
        // and an API payload, and this builds a filesystem path.
        $name = Users::isRunnableLoop($loop) ? $loop : 'client';
        $file = __DIR__ . '/loops/' . $name . '.md';
        $spec = is_file($file) ? (string) file_get_contents($file) : '';

        if (trim($spec) === '') {
            throw new RuntimeException("Loop specification for '{$loop}' is missing from app/loops/.");
        }

        return $spec;
    }

    public static function systemPrompt(string $loop): string
    {
        return "You are the 44i Prospector, a research analyst who finds and qualifies sales "
            . "prospects. You work from the loop specification below and you verify every claim "
            . "against a real source before you make it.\n\n"
            . "Non-negotiable rules:\n"
            . "- Never fabricate a company, person, title, email address, or phone number. If you "
            . "cannot find something, say so and give the fallback route instead.\n"
            . "- Every lead needs at least one specific piece of evidence you actually read — a page "
            . "on the company's own site, a filing, or a dated news article. Generic industry "
            . "assumptions are not evidence.\n"
            . "- Label every email address as verified (found on the org's own site, a filing, or a "
            . "press release), high (two or more independent sources agree), or pattern (inferred "
            . "from a known format, must be verified before sending).\n"
            . "- Apply the hard disqualifiers before scoring, not after.\n"
            . "- Deliver only leads that clear the fit-score floor. If fewer qualify than requested, "
            . "deliver what qualifies and say plainly how many you found and why. Never pad a batch "
            . "with weak leads.\n\n"
            . "=== LOOP SPECIFICATION ===\n\n"
            . self::loopSpec($loop);
    }

    /** @param list<string> $exclusions */
    public static function researchPrompt(
        string $loop,
        string $ownerName,
        string $vertical,
        string $geography,
        int $batchSize,
        int $minScore,
        array $exclusions
    ): string {
        $today = Clock::local()->format('l, F j, Y');

        $prompt = "Run today's batch for {$ownerName}.\n\n"
            . "Today is {$today}.\n"
            . "Focus for today: {$vertical}\n"
            . "Geography: {$geography}\n"
            . "Target count: {$batchSize} leads\n"
            . "Fit-score floor: {$minScore} — nothing below this ships\n\n";

        if ($exclusions !== []) {
            $shown = array_slice($exclusions, 0, 400);
            $prompt .= "ALREADY DELIVERED — do not return any of these companies, or a subsidiary, "
                . "sister station, or renamed version of one:\n"
                . implode('; ', $shown) . "\n";
            if (count($exclusions) > count($shown)) {
                $prompt .= '(plus ' . (count($exclusions) - count($shown)) . " older entries)\n";
            }
            $prompt .= "\n";
        } else {
            $prompt .= "This is the first batch for this owner, so there is nothing to exclude yet.\n\n";
        }

        $prompt .= "Method:\n"
            . "1. Pull a wide candidate pool from the sources in the specification — aim for 40 to 60 "
            . "names before filtering.\n"
            . "2. Apply the hard disqualifiers and the exclusion list above.\n"
            . "3. Score each survivor against the fit rubric. Inspect the company's own website to "
            . "decide which buyer door fits and what the signal is.\n"
            . "4. Enrich the ones that clear the floor: decision-maker name and title, email with a "
            . "confidence label, phone, LinkedIn where you can find it.\n"
            . "5. Write the brief.\n\n"
            . "Output format — a Markdown brief with these sections:\n\n"
            . "## Today's " . $batchSize . "\n"
            . "A table with one row per lead: Company | Vertical / Door | Market | Decision-maker & "
            . "title | Contact | Fit | Why them | Opening hook.\n"
            . "\"Why them\" is one line of specific evidence you actually read. \"Opening hook\" is "
            . "one line, matched to the buyer door, in plain spoken English.\n\n"
            . "## Contact detail\n"
            . "One short block per lead: name and title with its confidence, email with its "
            . "confidence label, direct phone, main phone, LinkedIn URL, website, and the fallback "
            . "route if the named contact does not pan out. Note any warm path or hiring signal.\n\n"
            . "## Notes\n"
            . "How many candidates you screened, how many cleared the floor, anything you flagged "
            . "for Scott to decide, and any lead you had to drop and why.\n";

        return $prompt;
    }

    /**
     * A field the model may legitimately have nothing for.
     *
     * Expressed as anyOf rather than a `["string","null"]` type array: anyOf is
     * in the documented structured-outputs subset, type arrays are not.
     *
     * @return array<string, mixed>
     */
    private static function optionalString(string $description): array
    {
        return [
            'description' => $description . ' Use null if the brief does not give it.',
            'anyOf' => [['type' => 'string'], ['type' => 'null']],
        ];
    }

    /**
     * Schema for the extraction pass. Structured outputs require
     * additionalProperties:false and every property listed in `required` on
     * each object, so optionality is carried by the type, not by omission.
     *
     * @return array<string, mixed>
     */
    public static function leadSchema(): array
    {
        $leadProperties = [
            'company' => [
                'type' => 'string',
                'description' => 'Company or organisation name, as the brief writes it.',
            ],
            'website' => self::optionalString('Primary website URL.'),
            'vertical' => self::optionalString('Vertical from the loop specification, e.g. Radio, Healthcare.'),
            'door' => self::optionalString('Buyer door, e.g. Fresh Start, Gap Filler, Agency Switcher.'),
            'market' => self::optionalString('City and state, or DMA.'),
            'state' => self::optionalString('Two-letter US state code.'),
            'decision_maker' => self::optionalString('Full name of the decision-maker.'),
            'title' => self::optionalString('Their job title.'),
            'email' => self::optionalString('Email address exactly as the brief gives it. Never invent one.'),
            'email_confidence' => [
                'description' => 'How well the email was verified. Null when there is no email.',
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['verified', 'high', 'pattern']],
                    ['type' => 'null'],
                ],
            ],
            'phone' => self::optionalString('Main switchboard number.'),
            'direct_phone' => self::optionalString('Direct desk number, if the brief found one.'),
            'linkedin' => self::optionalString('LinkedIn profile URL.'),
            'fit_score' => [
                'type' => 'integer',
                'description' => 'The fit score the brief assigned, 0 to 100. Do not recompute it.',
            ],
            'why' => [
                'type' => 'string',
                'description' => 'One line of the specific evidence that qualified this lead.',
            ],
            'hook' => [
                'type' => 'string',
                'description' => 'One line, door-matched opening hook.',
            ],
            'source' => self::optionalString('Where the candidate was sourced from.'),
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['leads', 'screened_count', 'notes'],
            'properties' => [
                'screened_count' => [
                    'type' => 'integer',
                    'description' => 'How many candidates the brief says were screened before filtering.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => "The brief's notes section, condensed to a few sentences.",
                ],
                'leads' => [
                    'type' => 'array',
                    'description' => 'One entry per company the brief delivered. Exclude anything it rejected.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => array_keys($leadProperties),
                        'properties' => $leadProperties,
                    ],
                ],
            ],
        ];
    }
}
