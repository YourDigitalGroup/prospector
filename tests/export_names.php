<?php

declare(strict_types=1);

/**
 * Splitting a decision maker's name, and the export columns built from it.
 *
 *     php bin/serve.php &
 *     php tests/export_names.php
 *
 * A lead stores one `decision_maker` string, because that is how a name
 * arrives — from a batch, a spreadsheet or a business card. First and last
 * name are worked out from it, in one place, so the CSV column and the
 * {{contact.first_name}} in an email cannot disagree about where someone's
 * first name ends. This checks that one place, and then checks the export
 * actually uses it.
 *
 * Local only. It signs in with the seeded dev account and writes leads.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\GoHighLevel;
use Prospector\Leads;
use Prospector\Merge;
use Prospector\Runs;
use Prospector\Support\Clock;
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

$split = static function (string $name, string $first, string $last, string $label) use ($check): void {
    $got = Merge::splitName($name);
    $check($got['first'] === $first && $got['last'] === $last,
        $label . ' — ' . ($name === '' ? '(blank)' : $name)
        . ' → [' . $got['first'] . '] [' . $got['last'] . ']');
};

// ------------------------------------------------------------- the split

$split('Dana Whitfield', 'Dana', 'Whitfield', 'an ordinary name');
$split('  Dana   Whitfield  ', 'Dana', 'Whitfield', 'untidy spacing');
$split('', '', '', 'nothing at all');
$split('Madonna', 'Madonna', '', 'one name only');
$split('Gwen Okonkwo-Hale', 'Gwen', 'Okonkwo-Hale', 'a hyphenated surname stays whole');
$split('Corinne Štefanić', 'Corinne', 'Štefanić', 'and so do accents');
$split('Marcus van der Berg', 'Marcus', 'van der Berg', 'a multi-part surname is all last name');
$split('Bea Oyelowo Jr.', 'Bea', 'Oyelowo Jr.', 'a suffix rides with the surname');

// Credentials after a comma, versus a list sorted by surname. The difference
// is whether the comma comes before or after the first space.
$split('Cale Slack, DDS', 'Cale', 'Slack', 'trailing credentials are dropped');
$split('Slack, Cale', 'Cale', 'Slack', 'a surname-first listing is read the right way round');

// Honorifics. Without this, {{contact.first_name}} resolves to "Dr." and a
// real prospect is greeted "Hi Dr.,". Healthcare and higher education are
// full of titled names, so this is not a curiosity.
$split('Dr. Yolanda Achebe', 'Yolanda', 'Achebe', 'a title is not a first name');
$split('Prof. Harlan Vossberg', 'Harlan', 'Vossberg', 'nor is Prof.');
$split('Rev Thomas Okafor', 'Thomas', 'Okafor', 'with or without the full stop');
$split('Mrs. Deon Fairbanks-Lu', 'Deon', 'Fairbanks-Lu', 'and the rest still splits normally');

// The two that would be easy to break by being clever about titles.
$split('Drew Hastings', 'Drew', 'Hastings', 'a real name that starts like a title is left alone');
$split('Dr.', 'Dr.', '', 'and a title with no name behind it is kept rather than blanked');

// ---------------------------------------------- what a send would greet

$lead = ['decision_maker' => 'Dr. Yolanda Achebe', 'company' => 'Three Rivers Health'];
$greeting = Merge::render('Hi {{contact.first_name}},', $lead);
echo "       greeting: {$greeting}\n";
$check($greeting === 'Hi Yolanda,', 'the email greeting uses the name, not the title');

$check(Merge::render('Hi {{contact.first_name}},', ['decision_maker' => '']) === 'Hi there,',
    'and falls back rather than blanking when there is no name at all');

// ------------------------------------------------------------ the export

$existing = (int) Database::scalar('SELECT COUNT(*) FROM leads');
if ($existing > 200) {
    fwrite(STDERR, "Refusing to run: {$existing} leads on file looks like real data.\n");
    exit(2);
}

Database::run('DELETE FROM leads');

$runId = Runs::start(2, 'partner', Clock::today(), 'upload', 'Test', '', 'test');
Leads::create(2, $runId, ['company' => 'Three Rivers Health', 'decision_maker' => 'Dr. Yolanda Achebe']);
Leads::create(2, $runId, ['company' => 'Bluestem Outdoor', 'decision_maker' => '']);

$jar = tempnam(sys_get_temp_dir(), 'prospector-cookies-');

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

$login = $http('/login');
preg_match('/name="csrf" value="([^"]+)"/', $login, $m);
$http('/login', ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => '']);

$csv = $http('/leads/export');
$lines = array_values(array_filter(explode("\n", trim($csv))));
$header = str_getcsv($lines[0]);

echo '       header: ' . implode(' | ', $header) . "\n";

$check(in_array('First name', $header, true), 'the export has a first name column');
$check(in_array('Last name', $header, true), 'and a last name column');
$check(in_array('Decision maker', $header, true), 'and still has the whole name it was split from');

$firstAt = array_search('First name', $header, true);
$lastAt = array_search('Last name', $header, true);
$wholeAt = array_search('Decision maker', $header, true);

$check($firstAt === $wholeAt + 1 && $lastAt === $wholeAt + 2,
    'the two new columns sit beside the name rather than off at the end');

// Every column that was there before is still there, in its old order.
$before = ['Company', 'Website', 'Vertical', 'Door', 'Market', 'State',
           'Decision maker', 'Title', 'Email', 'Email confidence', 'Direct phone',
           'Main phone', 'LinkedIn', 'Fit score', 'Why them', 'Opening hook',
           'Status', 'Owner', 'In GoHighLevel', 'Delivered'];
$kept = array_values(array_filter($header, static fn (string $h): bool => in_array($h, $before, true)));
$check($kept === $before, 'and nothing that was in the export before has moved or gone');

$rows = [];
foreach (array_slice($lines, 1) as $line) {
    $row = str_getcsv($line);
    $rows[$row[0]] = array_combine($header, $row);
}

$check(($rows['Three Rivers Health']['First name'] ?? '') === 'Yolanda',
    'a titled name exports a usable first name');
$check(($rows['Three Rivers Health']['Last name'] ?? '') === 'Achebe', 'and the surname');
$check(($rows['Three Rivers Health']['Decision maker'] ?? '') === 'Dr. Yolanda Achebe',
    'while the whole name is untouched, title and all');
$check(($rows['Bluestem Outdoor']['First name'] ?? 'x') === ''
    && ($rows['Bluestem Outdoor']['Last name'] ?? 'x') === '',
    'a lead with nobody named exports two empty cells, not a stray value');

// --------------------------------------------- and the same parts to GHL

// Pushing a contact used to run its own cruder split, so GoHighLevel got a
// first name of "Dr." while the export and the merge variables got "Yolanda".
// Only worth checking against the mock — PROSPECTOR_GHL_BASE is env-var-only
// so this can never reach the real API.
$ghlBase = getenv('PROSPECTOR_GHL_BASE');

if ($ghlBase === false || $ghlBase === '') {
    echo "SKIP  the GoHighLevel push (set PROSPECTOR_GHL_BASE to the mock to include it)\n";
} else {
    $client = new GoHighLevel('pit-mock-token', 'loc_44i_demo');
    $push = $client->pushLead([
        'company' => 'Three Rivers Health',
        'decision_maker' => 'Dr. Yolanda Achebe',
        'email' => '',
        'email_confidence' => 'pattern',
        'market' => 'Mankato, MN',
        'created_at' => Clock::today(),
    ]);

    $check($push['ok'] === true, 'a lead pushes to GoHighLevel');

    $contact = $client->contact((string) ($push['contact_id'] ?? ''))['contact'] ?? [];
    echo '       sent: firstName=[' . ($contact['firstName'] ?? '')
        . '] lastName=[' . ($contact['lastName'] ?? '') . "]\n";

    $check(($contact['firstName'] ?? '') === 'Yolanda',
        'and sends the same first name the export and the merge variables use');
    $check(($contact['lastName'] ?? '') === 'Achebe', 'and the same last name');
    $check(($contact['contactName'] ?? '') === 'Dr. Yolanda Achebe',
        'while the full name still carries the title');
}

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
Database::run('DELETE FROM runs WHERE id = :id', ['id' => $runId]);
@unlink($jar);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
