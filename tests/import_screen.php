<?php

declare(strict_types=1);

/**
 * The import screen, driven over HTTP the way a person drives it.
 *
 *     php bin/serve.php &            # or any host on PROSPECTOR_TEST_URL
 *     php tests/import_screen.php
 *
 * tests/import_update.php covers the matching and change rules directly. This
 * one covers the thing those tests cannot see: that the screen shows a person
 * what an upload is about to do, and that confirming it does exactly that and
 * nothing more. A preview that promised "3 new, 2 updated" and then rewrote
 * nine leads would pass every unit test in the suite.
 *
 * Local only. It signs in with the seeded dev account and writes leads.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Leads;
use Prospector\Support\Database;

$base = rtrim(getenv('PROSPECTOR_TEST_URL') ?: 'http://127.0.0.1:8099', '/');
$email = getenv('PROSPECTOR_TEST_EMAIL') ?: 'billy@44idigital.com';

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

$jar = tempnam(sys_get_temp_dir(), 'prospector-cookies-');

/**
 * @param array<string, string> $fields
 */
$http = static function (string $path, array $fields = []) use ($base, $jar): string {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($fields !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body = curl_exec($ch);

    if ($body === false) {
        fwrite(STDERR, 'Could not reach ' . $base . $path . ': ' . curl_error($ch) . "\n");
        fwrite(STDERR, "Start the server first: php bin/serve.php &\n");
        exit(2);
    }

    curl_close($ch);

    return (string) $body;
};

$csrf = static function (string $html): string {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
};

// ----------------------------------------------------------------- signing in

$login = $http('/login');
$token = $csrf($login);
$check($token !== '', 'the login page offers a CSRF token');

$home = $http('/login', ['csrf' => $token, 'email' => $email, 'password' => '']);
$check(!str_contains($home, 'name="email"') || str_contains($home, 'Sign out'),
    'the seeded dev account signs in');

$existing = (int) Database::scalar('SELECT COUNT(*) FROM leads');
if ($existing > 200) {
    fwrite(STDERR, "Refusing to run: {$existing} leads on file looks like real data.\n");
    exit(2);
}

Database::run('DELETE FROM leads');

// -------------------------------------------------------- the first upload

$first = <<<CSV
company,decision_maker,title,email,phone,fit_score
Prairie Sky Radio,Dana Whitfield,,,(712) 555-0142,70
Cedar Valley Health,Marcus Oyelaran,CMO,marcus@cedarvalley.example.com,,88
CSV;

$page = $http('/leads/import');
$preview = $http('/leads/import', ['csrf' => $csrf($page), 'raw' => $first, 'mode' => 'update']);

$check(str_contains($preview, 'Lands as'), 'the preview has a column saying what each row will do');
$check(substr_count($preview, 'New') >= 2, 'and both rows read as new');

preg_match('/name="parsed" value="([^"]*)"/', $preview, $m);
$parsed = html_entity_decode($m[1] ?? '', ENT_QUOTES);
$check($parsed !== '', 'the confirmed rows are carried forward');

$http('/leads/import', [
    'csrf' => $csrf($preview), 'confirm' => '1', 'parsed' => $parsed, 'mode' => 'update',
]);

$check((int) Database::scalar('SELECT COUNT(*) FROM leads') === 2, 'both land');

$dana = Database::first("SELECT * FROM leads WHERE decision_maker = 'Dana Whitfield'");
$check($dana !== null && $dana['phone'] === '(712) 555-0142', 'with what the file said');

// Work happens on one of them, the way it would between two lists arriving.
Leads::setStatus((int) $dana['id'], 'meeting', 'Call booked Tuesday', 1);

// ---------------------------------------------- the same list, come again

// Dana has gained an address and a title; her phone has been corrected; the
// score column is blank this time. Marcus is unchanged. Someone new is along.
$second = <<<CSV
company,decision_maker,title,email,phone,fit_score
Prairie Sky Radio,Dana Whitfield,Station Manager,dana@prairiesky.example.com,(712) 555-0199,
Cedar Valley Health,Marcus Oyelaran,CMO,marcus@cedarvalley.example.com,,88
Bluestem Outdoor,Rita Nakamura,Owner,rita@bluestem.example.com,,64
CSV;

$page = $http('/leads/import');
$preview = $http('/leads/import', ['csrf' => $csrf($page), 'raw' => $second, 'mode' => 'update']);

$check(str_contains($preview, 'Update'), 'a returning row is shown as an update, not a new lead');
$check(str_contains($preview, 'No change'), 'and one the file repeats verbatim as no change');
$check(str_contains($preview, 'was ('), 'the preview shows the value a change would replace');
$check(preg_match('/Add 1 and update 1/i', $preview) === 1,
    'the confirm button counts exactly one new and one changed');

// The preview describes the lead as it will be, not the row as it was typed.
// Dana's score column is blank in this file and her score is staying at 70;
// printing the file's bare 0 beside her would read as a score about to be
// wiped.
$check(preg_match('/>\s*70\s*</', $preview) === 1,
    'a column the file leaves blank shows the value that will survive, not an empty cell');
$check(preg_match('/>\s*0\s*</', $preview) !== 1,
    'and never shows a blank score column as a zero');

preg_match('/name="parsed" value="([^"]*)"/', $preview, $m);
$parsed = html_entity_decode($m[1] ?? '', ENT_QUOTES);

$after = $http('/leads/import', [
    'csrf' => $csrf($preview), 'confirm' => '1', 'parsed' => $parsed, 'mode' => 'update',
]);

$check((int) Database::scalar('SELECT COUNT(*) FROM leads') === 3,
    'the re-upload adds the one new person and duplicates nobody');

$dana = Database::first("SELECT * FROM leads WHERE decision_maker = 'Dana Whitfield'");
$check($dana['email'] === 'dana@prairiesky.example.com', 'Dana picks up her address');
$check($dana['phone'] === '(712) 555-0199', 'and the corrected number');
$check((int) $dana['fit_score'] === 70, 'the blank score column leaves her score alone');
$check($dana['status'] === 'meeting', 'and the work done on her survives the upload');
$check($dana['owner_note'] === 'Call booked Tuesday', 'note and all');

// ------------------------------------------------------------ the other modes

$third = <<<CSV
company,decision_maker,title,email,phone
Prairie Sky Radio,Dana Whitfield,Director of Sales,dana@prairiesky.example.com,(712) 555-0000
CSV;

$page = $http('/leads/import');
$preview = $http('/leads/import', ['csrf' => $csrf($page), 'raw' => $third, 'mode' => 'fill']);
$check(str_contains($preview, 'No change'),
    'in fill-blanks mode a row that only contradicts what is on file does nothing');

$preview = $http('/leads/import', ['csrf' => $csrf($page), 'raw' => $third, 'mode' => 'skip']);
$check(str_contains($preview, 'No change'), 'and leave-alone mode likewise');

$preview = $http('/leads/import', ['csrf' => $csrf($page), 'raw' => $third, 'mode' => 'update']);
$check(str_contains($preview, 'Update'), 'while update mode would change her');

preg_match('/name="parsed" value="([^"]*)"/', $preview, $m);
$parsed = html_entity_decode($m[1] ?? '', ENT_QUOTES);
$http('/leads/import', ['csrf' => $csrf($preview), 'confirm' => '1', 'parsed' => $parsed, 'mode' => 'fill']);

$dana = Database::first("SELECT * FROM leads WHERE decision_maker = 'Dana Whitfield'");
$check($dana['phone'] === '(712) 555-0199',
    'and confirming under fill-blanks honours the mode, not the preview it was built from');

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
@unlink($jar);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
