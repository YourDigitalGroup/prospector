<?php

declare(strict_types=1);

/**
 * CLI runner for the daily batch — the entry point for a cPanel cron job.
 *
 *   php bin/daily.php                 run scheduled users, if it is time
 *   php bin/daily.php --now           ignore the clock and the weekday rule
 *   php bin/daily.php --user=3        run one user by ID
 *   php bin/daily.php --user=billy@44idigital.com
 *   php bin/daily.php --no-email      run without emailing the brief
 *   php bin/daily.php --dry-run       print the prompt that would be sent, then stop
 *
 * Suggested crontab entry (weekdays, server clock set so it lands at 7:30 am
 * Central — the script re-checks the business clock before doing anything):
 *
 *   30 7 * * 1-5 /usr/local/bin/php /home/USER/public_html/bin/daily.php >> /home/USER/prospector-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/daily.php is a command-line script. Use cron.php for web scheduling.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Emails;
use Prospector\Prospector;
use Prospector\Runs;
use Prospector\Support\Background;
use Prospector\Support\Clock;
use Prospector\Support\Settings;
use Prospector\Users;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

$force = isset($options['now']) || isset($options['force']);
$dryRun = isset($options['dry-run']);
$sendEmail = !isset($options['no-email']);
$target = $options['user'] ?? null;

$now = Clock::local();

$say = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$say('Prospector — ' . $now->format('D, M j Y g:i a T'));

if (!$force && !$dryRun) {
    if (Settings::bool('run_weekdays_only', true) && Clock::isWeekend($now)) {
        $say('Weekend, and weekday-only delivery is on. Nothing to do.');
        exit(0);
    }

    // The window opens at the configured local time and stays open three hours,
    // so a cron job that fires a little early or late still lands inside it and
    // a failed batch gets retried by the next firing.
    $targetTime = $now->setTime(Settings::int('run_hour', 7), Settings::int('run_minute', 30));

    if ($now < $targetTime || $now >= $targetTime->modify('+3 hours')) {
        $say(sprintf(
            'Outside the delivery window (opens %s local, stays open 3 hours). Use --now to override.',
            $targetTime->format('g:i a')
        ));
        exit(0);
    }
}

/** @var list<array<string, mixed>> $users */
$users = [];

if ($target !== null && $target !== true) {
    $user = is_numeric($target) ? Users::find((int) $target) : Users::findByEmail((string) $target);
    if ($user === null) {
        $say('ERROR: no user matching "' . $target . '".');
        exit(1);
    }
    $users = [$user];
} else {
    $users = Users::scheduled();
}

if ($users === []) {
    $say('No users are scheduled for a batch.');
    exit(0);
}

if ($dryRun) {
    foreach ($users as $user) {
        $loop = (string) $user['loop'];
        if ($loop === 'none') {
            $say('-- ' . $user['name'] . ': no loop assigned, skipping.');
            continue;
        }

        $geography = trim((string) ($user['geography'] ?? '')) !== ''
            ? (string) $user['geography']
            : Runs::geographyFor($loop);

        $say('');
        $say('=========================================================');
        $say('DRY RUN — ' . $user['name'] . ' (' . Runs::loopLabel($loop) . ')');
        $say('=========================================================');
        $say('');
        $say('--- SYSTEM PROMPT ---');
        $say(Prospector::systemPrompt($loop));
        $say('');
        $say('--- RESEARCH PROMPT ---');
        $say(Prospector::researchPrompt(
            $loop,
            (string) $user['name'],
            Runs::verticalFor($loop),
            $geography,
            Settings::int('batch_size', 10),
            Settings::int('min_fit_score', 70),
            \Prospector\Leads::sentCompanies((int) $user['id'], Settings::int('dedupe_days', 365))
        ));
    }

    $say('');
    $say('Dry run only — nothing was sent to the API and nothing was stored.');
    exit(0);
}

// Send anything a person has already approved and that is due today.
//
// Above the engine and API-key checks on purpose: those govern researching new
// leads. An external worker or a missing key must not silently stall outreach
// that is already written and approved.
$outreach = Emails::sendDue();
$exitCode = 0;

if ($outreach['sent'] > 0 || $outreach['failed'] > 0 || $outreach['held'] > 0) {
    $say(sprintf(
        '  mail  %d sent, %d failed, %d held.',
        $outreach['sent'],
        $outreach['failed'],
        $outreach['held']
    ));
    foreach ($outreach['messages'] as $message) {
        $say('        ' . $message);
    }
    if ($outreach['failed'] > 0) {
        $exitCode = 1;
    }
}

$engine = Settings::engine();

if ($engine !== 'api') {
    $say(
        $engine === 'worker'
            ? 'Engine is set to the external worker, which pulls its own assignment. No batch to run here.'
            : 'Engine is set to manual paste-in. No batch runs on a schedule.'
    );
    // Not exit(0): a cadence email that failed to send above is still a failure
    // worth reporting to whatever is reading this script's exit code.
    exit($exitCode);
}

if (Settings::anthropicKey() === '') {
    $say('ERROR: no Anthropic API key configured. Add one under Settings, or set ANTHROPIC_API_KEY.');
    exit(1);
}

Background::extendLimits(3600);

foreach ($users as $user) {
    $existing = Runs::forUserOnDate((int) $user['id'], Clock::today());

    if (!$force && $existing !== null && in_array((string) $existing['status'], ['success', 'partial', 'running'], true)) {
        $say('  skip  ' . $user['name'] . ' — already ran today.');
        continue;
    }

    $say('  run   ' . $user['name'] . ' (' . Runs::loopLabel((string) $user['loop']) . ')…');

    $result = Prospector::runFor($user, 'cron', $sendEmail);

    $say(($result['ok'] ? '  ok    ' : '  FAIL  ') . $user['name'] . ' — ' . $result['message']);
    Background::log('CLI ' . $user['name'] . ': ' . $result['message']);

    if (!$result['ok']) {
        $exitCode = 1;
    }
}

exit($exitCode);
