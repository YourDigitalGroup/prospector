<?php

declare(strict_types=1);

/**
 * When Anthropic refuses the API key.
 *
 *     php tests/anthropic_key.php
 *
 * The first time the schedule ran for real, every batch died on
 * "401 invalid x-api-key". This covers what Prospector does about that: keys are
 * trimmed, a new key is checked with Anthropic before it is kept, and once a
 * key has been refused the schedule stops retrying it until it is replaced.
 *
 * Starts its own mock Anthropic API and its own copy of the app pointed at it,
 * so nothing here reaches the real API or spends anything. Settings are
 * snapshotted first and put back afterwards.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Claude;
use Prospector\Prospector;
use Prospector\Support\Database;
use Prospector\Support\Settings;

$root = dirname(__DIR__);
$pass = 0;
$failures = [];

$check = static function (bool $ok, string $label) use (&$pass, &$failures): void {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";

        return;
    }

    $failures[] = $label;
    echo "FAIL {$label}\n";
};

$existing = (int) Database::scalar('SELECT COUNT(*) FROM leads');
if ($existing > 200) {
    fwrite(STDERR, "Refusing to run: {$existing} leads on file looks like real data.\n");
    exit(2);
}

// ----------------------------------------------------------------- set up

$freePort = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
};

$mockPort = $freePort();
$appPort = $freePort();
$mockUrl = 'http://127.0.0.1:' . $mockPort;
$appUrl = 'http://127.0.0.1:' . $appPort;

$mock = proc_open(['python3', $root . '/tests/mock_anthropic.py', (string) $mockPort],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$app = proc_open(['php', '-S', '127.0.0.1:' . $appPort, '-t', $root, $root . '/bin/serve.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
    array_merge(getenv(), ['ANTHROPIC_BASE_URL' => $mockUrl]));

$waitFor = static function (string $url): void {
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents($url) !== false || str_contains(implode(' ', $http_response_header ?? []), 'HTTP/')) {
            return;
        }
        usleep(100000);
    }
};
$waitFor($mockUrl . '/__calls');
$waitFor($appUrl . '/login');

putenv('ANTHROPIC_BASE_URL=' . $mockUrl);

$snapshot = Database::all('SELECT * FROM settings');
$restore = static function () use ($snapshot): void {
    Database::run('DELETE FROM settings');
    foreach ($snapshot as $row) {
        Database::insert('settings', $row);
    }
};

$cleanup = static function () use ($mock, $app, $restore): void {
    $restore();
    proc_terminate($mock);
    proc_terminate($app);
};

register_shutdown_function($cleanup);

$good = 'sk-ant-api03-good-test-key';
$revoked = 'sk-ant-api03-revoked-test-key';

// ------------------------------------------------- checking a key

$result = Claude::checkKey($good);
$check($result['ok'] && !$result['rejected'], 'a good key is accepted — ' . $result['message']);

$result = Claude::checkKey($revoked);
$check(!$result['ok'] && $result['rejected'], 'a revoked key is refused');
$check(str_contains($result['message'], 'invalid x-api-key') && str_contains($result['message'], 'console.anthropic.com'),
    'and says why, and where to get a new one');

putenv('ANTHROPIC_BASE_URL=http://127.0.0.1:1');
$result = Claude::checkKey($good);
$check(!$result['ok'] && !$result['rejected'],
    'Anthropic being unreachable is not mistaken for the key being bad');
putenv('ANTHROPIC_BASE_URL=' . $mockUrl);

// ------------------------------------------------- whitespace

// Copied from the console with a line break on the end. Before, this was sent
// as-is and refused as invalid.
Settings::set('anthropic_api_key', $good . "\n");
$check(Settings::anthropicKey() === $good, 'a key saved with a trailing line break is read back trimmed');
$check(Claude::checkKey(Settings::anthropicKey())['ok'], 'and works');

// ------------------------------------------------- a batch meets a refused key

$darren = Database::first("SELECT * FROM users WHERE loop = 'client'");
Settings::set('anthropic_api_key', $revoked);
Settings::forget('anthropic_key_rejected');
Settings::set('engine', 'api');

$check(!Prospector::keyRefused(), 'nothing is held before anything has been refused');

$result = Prospector::runFor($darren, 'test', false);
$check(!$result['ok'] && str_contains($result['message'], 'invalid x-api-key'),
    'a batch with a revoked key fails the way it did in production');
$check(Prospector::keyRefused(), 'and the refusal is remembered against that key');

$stored = Settings::get('anthropic_key_rejected');
$check($stored !== '' && !str_contains($stored, 'sk-ant'), 'as a fingerprint — the key itself is not written anywhere new');

$runsBefore = (int) Database::scalar('SELECT COUNT(*) FROM runs');
$held = Prospector::runScheduled('test', true);
$runsAfter = (int) Database::scalar('SELECT COUNT(*) FROM runs');
$check($runsAfter === $runsBefore, 'the schedule then makes no further attempt — no new failed batches pile up');
$check(count($held) === 1 && str_contains($held[0]['message'], 'Settings → Anthropic API'),
    'and says once, plainly, what to do instead');

// The webhook the GitHub Action and a cPanel cron job call.
// ignore_errors, or PHP throws away the body of a 500 and the message with it.
$cronOut = @file_get_contents($appUrl . '/cron.php?token=' . urlencode(Settings::cronToken()) . '&force=1&wait=1',
    false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 60]]));
$cronStatus = (int) substr(($http_response_header[0] ?? ''), 9, 3);
echo "       cron answered {$cronStatus}: " . trim(str_replace("\n", ' | ', (string) $cronOut)) . "\n";
$check($cronStatus === 500 && str_contains((string) $cronOut, 'Anthropic refused the API key'),
    'the cron webhook holds too, and answers with an error so the scheduler shows red');
$check((int) Database::scalar('SELECT COUNT(*) FROM runs') === $runsBefore, 'without starting a batch');

// A different key lifts the hold by itself.
Settings::set('anthropic_api_key', $good);
$check(!Prospector::keyRefused(), 'a new key lifts the hold without anyone having to clear it');

// ------------------------------------------------- the settings screen

Settings::set('anthropic_api_key', $revoked);
Settings::set('anthropic_key_rejected', Prospector::keyFingerprint());

$jar = tempnam(sys_get_temp_dir(), 'prospector-cookies-');
$http = static function (string $path, array $fields = []) use ($appUrl, $jar): string {
    $ch = curl_init($appUrl . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($fields !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);

    return $body;
};
$csrf = static function (string $html): string {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
};

$login = $http('/login');
$http('/login', ['csrf' => $csrf($login), 'email' => 'scott@44interactive.com', 'password' => '44i123']);

$page = $http('/settings');
$check(str_contains($page, 'Refused by Anthropic'), 'the settings screen says the key was refused, not "Key set"');
$check(str_contains($page, 'scheduled batches are on hold'), 'and that batches are on hold until it is replaced');

// Post the whole settings form back, as a browser would, with only the key changed.
$form = static function (string $html): array {
    $fields = [];
    preg_match_all('/<input[^>]*>/', $html, $inputs);
    foreach ($inputs[0] as $input) {
        if (!preg_match('/name="([^"]+)"/', $input, $n)) {
            continue;
        }
        $type = preg_match('/type="([^"]+)"/', $input, $t) ? $t[1] : 'text';
        if (in_array($type, ['checkbox', 'radio'], true) && !str_contains($input, 'checked')) {
            continue;
        }
        preg_match('/value="([^"]*)"/', $input, $v);
        $fields[$n[1]] = html_entity_decode($v[1] ?? '', ENT_QUOTES);
    }
    preg_match_all('/<select[^>]*name="([^"]+)"[^>]*>(.*?)<\/select>/s', $html, $selects, PREG_SET_ORDER);
    foreach ($selects as $select) {
        $fields[$select[1]] = preg_match('/<option value="([^"]*)"[^>]*selected/', $select[2], $o) ? $o[1]
            : (preg_match('/<option value="([^"]*)"/', $select[2], $o) ? $o[1] : '');
    }

    return $fields;
};

$fields = $form($page);
$fields['anthropic_api_key'] = 'sk-ant-api03-another-revoked-key';
$after = $http('/settings', $fields);
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
$check(str_contains($after, 'That key was not saved') && str_contains($after, 'invalid x-api-key'),
    'saving a key Anthropic refuses says so and does not keep it');
$check(Settings::anthropicKey() === $revoked, 'the key on file is left as it was');

$fields = $form($http('/settings'));
$fields['anthropic_api_key'] = '  ' . $good . "\n";
$after = $http('/settings', $fields);

// Settings are cached for the life of a process, and this one is not the
// server that just saved them. Read them afresh.
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
$check(str_contains($after, 'Anthropic accepted the new key'), 'saving a good one says Anthropic accepted it');
$check(Settings::anthropicKey() === $good, 'and keeps it, trimmed');
$check(!Prospector::keyRefused(), 'which lifts the hold');
$check(!str_contains($http('/settings'), 'Refused by Anthropic'), 'and the screen goes back to "Key set"');

@unlink($jar);

// --------------------------------------------------------------- results

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
