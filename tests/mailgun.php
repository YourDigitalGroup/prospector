<?php

declare(strict_types=1);

/**
 * Sending email through Mailgun.
 *
 *     php tests/mailgun.php
 *
 * Starts its own Mailgun stand-in (tests/mock_mailgun.py) and its own copy of
 * the app pointed at it, so nothing is actually sent. Settings are snapshotted
 * first and put back afterwards.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Leads;
use Prospector\Mailer;
use Prospector\Reminders;
use Prospector\Runs;
use Prospector\Support\Clock;
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

if ((int) Database::scalar('SELECT COUNT(*) FROM leads') > 200) {
    fwrite(STDERR, "Refusing to run: this looks like real data.\n");
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

$mock = proc_open(['python3', $root . '/tests/mock_mailgun.py', (string) $mockPort],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$app = proc_open(['php', '-S', '127.0.0.1:' . $appPort, '-t', $root, $root . '/bin/serve.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
    array_merge(getenv(), ['PROSPECTOR_MAILGUN_BASE' => $mockUrl]));

for ($i = 0; $i < 50 && @file_get_contents($mockUrl . '/__messages') === false; $i++) {
    usleep(100000);
}
for ($i = 0; $i < 50 && @file_get_contents($appUrl . '/login') === false; $i++) {
    usleep(100000);
}

putenv('PROSPECTOR_MAILGUN_BASE=' . $mockUrl);

$snapshot = Database::all('SELECT * FROM settings');
register_shutdown_function(static function () use ($snapshot, $mock, $app): void {
    Database::run('DELETE FROM settings');
    foreach ($snapshot as $row) {
        Database::insert('settings', $row);
    }
    proc_terminate($mock);
    proc_terminate($app);
});

$fresh = static fn () => (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
$messages = static fn (): array => json_decode((string) file_get_contents($mockUrl . '/__messages'), true);
$reset = static fn () => file_get_contents($mockUrl . '/__reset');

// ------------------------------------------------- sending

Settings::set('mail_transport', 'mailgun');
Settings::set('mailgun_domain', 'fourge.com');
Settings::set('mailgun_api_key', 'key-good-test');
Settings::set('mail_from_email', '');
Settings::set('mail_from_name', 'Prospector');

$reset();
$result = Mailer::send('darren@44i.com', 'Darren Kuyper', 'Follow up: Wapiti Ridge', '<p>Call <b>Deon</b> back.</p>');
$sent = $messages()[0] ?? [];

$check($result['ok'], 'an email goes out through Mailgun — ' . $result['message']);
$check(($sent['domain'] ?? '') === 'fourge.com', 'from the fourge.com sending domain');
$check(($sent['from'] ?? '') === '"Prospector" <prospector@fourge.com>',
    'as prospector@fourge.com when no From address is set — ' . ($sent['from'] ?? ''));
$check(($sent['to'] ?? '') === '"Darren Kuyper" <darren@44i.com>', 'to the right person');
$check(($sent['subject'] ?? '') === 'Follow up: Wapiti Ridge', 'with the subject');
$check(str_contains($sent['html'] ?? '', '<b>Deon</b>'), 'the HTML body');
$check(($sent['text'] ?? '') === 'Call Deon back.', 'and a plain-text version for clients that want one');

Settings::set('mail_from_email', 'scott@fourge.com');
$reset();
Mailer::send('darren@44i.com', 'Darren', 'Hi', '<p>x</p>');
$check(($messages()[0]['from'] ?? '') === '"Prospector" <scott@fourge.com>', 'a From address that is set is used');
Settings::set('mail_from_email', '');

// A name cannot be used to slip in another header.
$reset();
Mailer::send('darren@44i.com', "Darren\r\nBcc: someone@else.example", "Subject\r\nBcc: x@y.example", '<p>x</p>');
$sent = $messages()[0] ?? [];
$check(!str_contains($sent['to'] ?? '', "\n") && !str_contains($sent['subject'] ?? '', "\n"),
    'line breaks are taken out of names and subjects, so nothing can be added as a header');

// ------------------------------------------------- when Mailgun says no

Settings::set('mailgun_api_key', 'key-bad-test');
$result = Mailer::send('darren@44i.com', 'Darren', 'Hi', '<p>x</p>');
$check(!$result['ok'] && str_contains($result['message'], 'refused the API key'),
    'a wrong key is reported as a wrong key — ' . $result['message']);

Settings::set('mailgun_api_key', 'key-good-test');
Settings::set('mailgun_domain', 'fourge.co');
$result = Mailer::send('darren@44i.com', 'Darren', 'Hi', '<p>x</p>');
$check(!$result['ok'] && str_contains($result['message'], 'no sending domain called "fourge.co"'),
    'a domain Mailgun does not have is named — ' . $result['message']);
$check(str_contains($result['message'], 'EU'), 'with the region mix-up that usually causes it');

Settings::set('mailgun_domain', '');
$result = Mailer::send('darren@44i.com', 'Darren', 'Hi', '<p>x</p>');
$check(!$result['ok'] && str_contains($result['message'], 'missing'), 'a half-filled setup says what is missing');
Settings::set('mailgun_domain', 'fourge.com');

// ------------------------------------------------- a reminder goes out

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');
$darren = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")['id'];
$run = Runs::start($darren, 'client', Clock::today(), 'upload', 'Mailgun test', '', 'test');
$lead = Leads::create($darren, $run, ['company' => 'Wapiti Ridge', 'decision_maker' => 'Deon Fairbanks']);
$reminder = Reminders::create($lead, $darren, gmdate('Y-m-d H:i:s', strtotime('-2 minutes')), 'Call about the RFP');

$reset();
$swept = Reminders::sendDue();
$sent = $messages()[0] ?? [];
$check($swept['sent'] === 1, 'a due reminder is sent through Mailgun by the normal sweep');
$check(str_contains($sent['subject'] ?? '', 'Follow up: Wapiti Ridge') && str_contains($sent['html'] ?? '', 'Call about the RFP'),
    'with the reminder in it');
$check(Reminders::find($reminder)['sent_at'] !== null, 'and is marked sent');

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');
Database::run('DELETE FROM runs WHERE id = :id', ['id' => $run]);

// ------------------------------------------------- the settings screen

Settings::set('mail_transport', 'mail');
Settings::set('mailgun_domain', '');
Settings::forget('mailgun_api_key');

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

$login = $http('/login');
$http('/login', ['csrf' => $csrf($login), 'email' => 'scott@44interactive.com', 'password' => '44i123']);

$page = $http('/settings');
$check(str_contains($page, '<option value="mailgun"'), 'Settings offers Mailgun as a way to send');
$check(str_contains($page, 'name="mailgun_domain"') && str_contains($page, 'name="mailgun_api_key"'),
    'with a place for the sending domain and the API key');

$fields = $form($page);
$fields['mail_transport'] = 'mailgun';
$fields['mailgun_domain'] = ' https://Fourge.com/ ';
$fields['mailgun_api_key'] = ' key-good-from-screen ';
$http('/settings', $fields);
$fresh();

$check(Settings::get('mail_transport') === 'mailgun', 'choosing Mailgun is saved');
$check(Settings::get('mailgun_domain') === 'fourge.com', 'a pasted URL is cut down to the domain');
$check(Settings::get('mailgun_api_key') === 'key-good-from-screen', 'the key is saved, trimmed');

$stored = (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'mailgun_api_key'");
$check(!str_contains($stored, 'key-good-from-screen'), 'and stored encrypted, not as typed');
$check(!str_contains($http('/settings'), 'key-good-from-screen'), 'and never shown back on the screen');

// Saving the screen again without retyping the key keeps it.
$http('/settings', $form($http('/settings')));
$fresh();
$check(Settings::get('mailgun_api_key') === 'key-good-from-screen', 'saving other settings leaves the key alone');

// The existing Send a test button.
$reset();
$fields = $form($http('/settings'));
$fields['test_email'] = 'scott@44interactive.com';
$after = $http('/settings/test/email', $fields);
$sent = $messages();
$check(count($sent) === 1 && ($sent[0]['to'] ?? '') !== '' && str_contains($sent[0]['to'], 'scott@44interactive.com'),
    'Send a test goes through Mailgun');
$check(str_contains($after, 'via Mailgun'), 'and says so');

@unlink($jar);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
