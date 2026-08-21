<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Crypto;
use Prospector\Support\Request;
use Prospector\Support\Settings;

/**
 * JSON API for an external batch worker.
 *
 * This exists so the machine doing the research does not have to be the machine
 * serving the dashboard. The worker pulls its assignment, does the work
 * wherever it lives, and posts the results back — all outbound HTTPS, so
 * nothing on the worker's network has to be exposed.
 *
 * The same endpoints back the manual paste-in flow.
 */
final class Api
{
    private const MAX_BODY = 4 * 1024 * 1024;

    public static function handle(string $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');

        if (!self::authorised()) {
            // Slow enough that guessing the token is unattractive.
            usleep(400000);
            self::fail(401, 'Bad or missing worker token.');
        }

        try {
            match ($action) {
                'assignment' => self::assignment(),
                'import' => self::import(),
                'heartbeat' => self::heartbeat(),
                default => self::fail(404, 'Unknown endpoint.'),
            };
        } catch (\Throwable $e) {
            \Prospector\Support\Background::log('API error on ' . $action . ': ' . $e->getMessage());
            self::fail(500, $e->getMessage());
        }
    }

    private static function authorised(): bool
    {
        $expected = Settings::workerToken();
        $given = '';

        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($header, 'bearer ') === 0) {
            $given = trim(substr($header, 7));
        }

        if ($given === '') {
            $given = Request::input('token');
        }

        return Crypto::matches($expected, $given);
    }

    /**
     * What should the worker go and find today?
     *
     * GET /api/assignment?email=billy@44idigital.com
     */
    private static function assignment(): void
    {
        $email = Request::input('email');
        $users = [];

        if ($email !== '') {
            $user = Users::findByEmail($email);
            if ($user === null || (int) $user['active'] !== 1) {
                self::fail(404, 'No active account for ' . $email . '.');
            }
            $users = [$user];
        } else {
            // No email given: hand back every scheduled user, so one worker run
            // can cover the whole team.
            $users = Users::scheduled();
        }

        $today = Clock::today();
        $batchSize = Settings::int('batch_size', 10);
        $minScore = Settings::int('min_fit_score', 70);
        $assignments = [];

        foreach ($users as $user) {
            $loop = (string) $user['loop'];
            if (!Users::isRunnableLoop($loop)) {
                continue;
            }

            $existing = Runs::forUserOnDate((int) $user['id'], $today);
            $done = $existing !== null
                && in_array((string) $existing['status'], ['success', 'partial'], true);

            $assignments[] = [
                'email' => (string) $user['email'],
                'name' => (string) $user['name'],
                'loop' => $loop,
                'loop_label' => Runs::loopLabel($loop),
                'vertical' => Runs::verticalFor($loop),
                'geography' => trim((string) ($user['geography'] ?? '')) !== ''
                    ? (string) $user['geography']
                    : Runs::geographyFor($loop),
                'batch_size' => $batchSize,
                'min_fit_score' => $minScore,
                'already_ran_today' => $done,
                // Never return these companies. Normalised keys are included so
                // the worker can match without reimplementing the rules.
                'exclude' => Leads::sentCompanies((int) $user['id'], Settings::int('dedupe_days', 365)),
                'exclude_keys' => array_values(array_unique(array_map(
                    [Leads::class, 'companyKey'],
                    Leads::sentCompanies((int) $user['id'], Settings::int('dedupe_days', 365))
                ))),
            ];
        }

        self::ok([
            'date' => $today,
            'timezone' => Clock::timezoneName(),
            'weekend' => Clock::isWeekend(),
            'weekdays_only' => Settings::bool('run_weekdays_only', true),
            'assignments' => $assignments,
        ]);
    }

    /**
     * Take a finished batch.
     *
     * POST /api/import
     * {
     *   "email": "billy@44idigital.com",
     *   "engine": "ollama:qwen3:8b",
     *   "vertical": "Radio broadcasters",
     *   "geography": "Iowa, Nebraska and Kansas",
     *   "screened_count": 187,
     *   "notes": "…",
     *   "brief_md": "…",          // optional, shown on the batch screen
     *   "leads": [ { … } ],
     *   "send_email": true         // optional, defaults to the user's setting
     * }
     */
    private static function import(): void
    {
        $payload = self::body();

        $email = trim((string) ($payload['email'] ?? ''));
        $user = $email !== '' ? Users::findByEmail($email) : null;

        if ($user === null || (int) $user['active'] !== 1) {
            self::fail(404, 'No active account for "' . $email . '".');
        }

        $loop = (string) ($payload['loop'] ?? $user['loop']);
        if (!Users::isRunnableLoop($loop)) {
            self::fail(422, 'That user has no prospecting loop assigned.');
        }

        $leads = $payload['leads'] ?? null;
        if (!is_array($leads)) {
            self::fail(422, 'Expected a "leads" array.');
        }

        $minScore = Settings::int('min_fit_score', 70);
        $engine = mb_substr(trim((string) ($payload['engine'] ?? 'worker')), 0, 60);

        $runId = Runs::start(
            (int) $user['id'],
            $loop,
            Clock::today(),
            'worker',
            mb_substr((string) ($payload['vertical'] ?? Runs::verticalFor($loop)), 0, 190),
            mb_substr((string) ($payload['geography'] ?? ''), 0, 190),
            $engine
        );

        $stored = 0;
        $skipped = 0;
        $rejected = [];

        foreach ($leads as $index => $lead) {
            if (!is_array($lead)) {
                $rejected[] = 'entry ' . $index . ' was not an object';
                continue;
            }

            if (trim((string) ($lead['company'] ?? '')) === '') {
                $rejected[] = 'entry ' . $index . ' had no company name';
                continue;
            }

            // The score floor is enforced here as well as in the worker, so a
            // buggy or over-eager worker cannot lower the bar.
            if ((int) ($lead['fit_score'] ?? 0) < $minScore) {
                $skipped++;
                continue;
            }

            $id = Leads::create((int) $user['id'], $runId, $lead);

            if ($id > 0) {
                $stored++;
                Leads::addActivity(
                    $id,
                    null,
                    'created',
                    'Delivered by the ' . Runs::loopLabel($loop) . ' batch (' . $engine . ')'
                );
            } else {
                $skipped++;
            }
        }

        $notes = trim((string) ($payload['notes'] ?? ''));
        $brief = trim((string) ($payload['brief_md'] ?? ''));

        if ($brief === '' && ($notes !== '' || $stored > 0)) {
            $brief = self::briefFrom($payload, $stored, $skipped, $notes);
        }

        Runs::finish($runId, [
            'status' => $stored > 0 ? 'success' : 'partial',
            'lead_count' => $stored,
            'brief_md' => $brief !== '' ? $brief : null,
            'input_tokens' => (int) ($payload['input_tokens'] ?? 0),
            'output_tokens' => (int) ($payload['output_tokens'] ?? 0),
        ]);

        $emailed = false;
        $emailError = null;
        $wantsEmail = array_key_exists('send_email', $payload)
            ? (bool) $payload['send_email']
            : (int) ($user['daily_email'] ?? 0) === 1;

        if ($wantsEmail) {
            $run = Runs::find($runId);
            if ($run !== null) {
                $result = Mailer::sendDailyBrief($user, $run, Leads::forRun($runId));
                $emailed = $result['ok'];
                if (!$result['ok']) {
                    $emailError = $result['message'];
                } else {
                    Runs::markEmailed($runId);
                }
            }
        }

        Settings::set('worker_last_seen', Clock::now());

        self::ok([
            'run_id' => $runId,
            'stored' => $stored,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'min_fit_score' => $minScore,
            'emailed' => $emailed,
            'email_error' => $emailError,
            'url' => \Prospector\Support\View::url('runs/' . $runId),
        ]);
    }

    /**
     * Let the dashboard notice a worker that has stopped checking in — a
     * sleeping Mac should be visible, not a silently missing batch.
     */
    private static function heartbeat(): void
    {
        $payload = self::body(false);

        Settings::set('worker_last_seen', Clock::now());
        Settings::set('worker_label', mb_substr(trim((string) ($payload['worker'] ?? 'worker')), 0, 80));

        if (isset($payload['engine'])) {
            Settings::set('worker_engine', mb_substr(trim((string) $payload['engine']), 0, 80));
        }

        self::ok(['seen_at' => Clock::now()]);
    }

    /** @param array<string, mixed> $payload */
    private static function briefFrom(array $payload, int $stored, int $skipped, string $notes): string
    {
        $lines = ['## Batch summary', ''];

        $screened = (int) ($payload['screened_count'] ?? 0);
        if ($screened > 0) {
            $lines[] = '- Candidates screened: **' . $screened . '**';
        }
        $lines[] = '- Delivered: **' . $stored . '**';
        if ($skipped > 0) {
            $lines[] = '- Skipped as duplicates or below the score floor: ' . $skipped;
        }
        if (isset($payload['engine'])) {
            $lines[] = '- Engine: `' . (string) $payload['engine'] . '`';
        }

        if ($notes !== '') {
            $lines[] = '';
            $lines[] = '## Notes';
            $lines[] = '';
            $lines[] = $notes;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private static function body(bool $required = true): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            if ($required) {
                self::fail(400, 'Expected a JSON body.');
            }

            return [];
        }

        if (strlen($raw) > self::MAX_BODY) {
            self::fail(413, 'Body too large.');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            self::fail(400, 'Body was not valid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data): never
    {
        http_response_code(200);
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private static function fail(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
