<?php

declare(strict_types=1);

/**
 * Webhook entry point for the daily batch.
 *
 *   cron.php?token=SECRET             run every scheduled user, if it is time
 *   cron.php?token=SECRET&force=1     run regardless of the clock or weekday
 *   cron.php?token=SECRET&user=3      run one user
 *   cron.php?token=SECRET&wait=1      run in the foreground and print results
 *
 * The token comes from Settings → Scheduling hook. Anyone holding it can start
 * a batch, so treat the URL as a credential.
 */

require __DIR__ . '/app/bootstrap.php';

use Prospector\Prospector;
use Prospector\Support\Background;
use Prospector\Support\Clock;
use Prospector\Support\Crypto;
use Prospector\Support\Settings;
use Prospector\Users;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if (!Crypto::matches(Settings::cronToken(), $token)) {
    // Deliberately vague, and slow enough that guessing is unattractive.
    usleep(400000);
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$force = isset($_GET['force']) || isset($_POST['force']);
$wait = isset($_GET['wait']) || isset($_POST['wait']);
$userId = isset($_GET['user']) ? (int) $_GET['user'] : 0;

$now = Clock::local();
$targetHour = Settings::int('run_hour', 7);
$targetMinute = Settings::int('run_minute', 30);
$weekdaysOnly = Settings::bool('run_weekdays_only', true);

/**
 * The webhook is pinged on a fixed interval, so this decides which ping is the
 * real one: the window opens at the configured local time and stays open for
 * three hours. The first ping inside it starts the batch; later pings find the
 * run already done and skip. A failed run is retried by the next ping, which is
 * why the window is generous rather than exact.
 *
 * Anchoring on local time is also what makes this daylight-saving-proof — the
 * caller's UTC schedule never has to change.
 */
$withinWindow = static function () use ($now, $targetHour, $targetMinute): bool {
    $target = $now->setTime($targetHour, $targetMinute);

    return $now >= $target && $now < $target->modify('+3 hours');
};

$lines = [];
$lines[] = 'Prospector cron — ' . $now->format('D, M j Y g:i a T');

if (!$force) {
    if ($weekdaysOnly && Clock::isWeekend($now)) {
        $lines[] = 'Weekend, and weekday-only delivery is on. Nothing to do.';
        echo implode("\n", $lines) . "\n";
        exit;
    }

    if (!$withinWindow()) {
        $lines[] = sprintf(
            'Outside the delivery window (opens %s local, stays open 3 hours). Nothing to do.',
            $now->setTime($targetHour, $targetMinute)->format('g:i a')
        );
        echo implode("\n", $lines) . "\n";
        exit;
    }
}

// Check the engine before the key: with an external worker or manual paste-in
// selected there is nothing for this endpoint to do, and no key is needed.
$engine = Settings::engine();

if ($engine !== 'api') {
    $lines[] = $engine === 'worker'
        ? 'Engine is set to the external worker, which pulls its own assignment. Nothing to do here.'
        : 'Engine is set to manual paste-in. Nothing runs on a schedule.';
    echo implode("\n", $lines) . "\n";
    exit;
}

if (Settings::anthropicKey() === '') {
    http_response_code(500);
    $lines[] = 'ERROR: no Anthropic API key configured. Set one under Settings.';
    Background::log('Cron aborted: missing Anthropic API key.');
    echo implode("\n", $lines) . "\n";
    exit;
}

// Decide up front who is in scope so the acknowledgement is informative.
if ($userId > 0) {
    $user = Users::find($userId);
    $targets = ($user !== null && (string) $user['loop'] !== 'none') ? [$user] : [];
} else {
    $targets = Users::scheduled();
}

if ($targets === []) {
    $lines[] = 'No users are scheduled for a batch.';
    echo implode("\n", $lines) . "\n";
    exit;
}

$names = array_map(static fn (array $u): string => (string) $u['name'], $targets);
$lines[] = 'Starting batches for: ' . implode(', ', $names);

$run = static function () use ($targets, $userId, $force): array {
    if ($userId > 0) {
        $result = Prospector::runFor($targets[0], 'cron');

        return [[
            'user' => (string) $targets[0]['name'],
            'ok' => $result['ok'],
            'message' => $result['message'],
        ]];
    }

    return Prospector::runScheduled('cron', $force);
};

// Batches take minutes. Acknowledge the ping first where the host allows it so
// the caller is not left holding an open connection.
if (!$wait && Background::canDetach()) {
    $lines[] = 'Running in the background. Check the Batches screen for results.';
    Background::respondThenContinue(implode("\n", $lines) . "\n", 202);
    Background::extendLimits(3600);

    foreach ($run() as $result) {
        Background::log('Cron ' . $result['user'] . ': ' . $result['message']);
    }

    exit;
}

Background::extendLimits(3600);

foreach ($run() as $result) {
    $lines[] = ($result['ok'] ? '  ok   ' : '  FAIL ') . $result['user'] . ' — ' . $result['message'];
    Background::log('Cron ' . $result['user'] . ': ' . $result['message']);
}

echo implode("\n", $lines) . "\n";
