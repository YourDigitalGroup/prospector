<?php

declare(strict_types=1);

/**
 * Canonical verticals, and logging what you did to a lead.
 *
 *     php bin/serve.php &
 *     php tests/actions_verticals.php
 *
 * Two features that meet in the leads list: a vertical column that reads as
 * one heading per thing rather than fifteen spellings of it, and an action
 * trail that says what has actually been done and when.
 *
 * Local only. It signs in with the seeded dev account and writes leads.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\Database;
use Prospector\Support\Schema;
use Prospector\Support\Settings;
use Prospector\Users;
use Prospector\Verticals;

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

// ------------------------------------------------------- bucketing the text

$bucket = static function (string $raw, string $expected) use ($check): void {
    $got = Verticals::canonical($raw, 'client');
    $check($got === $expected, sprintf('%-30s → %s', $raw === '' ? '(blank)' : $raw, $got));
};

// The fifteen casinos this exists for.
foreach (['Casino', 'Casinos', 'Tribal gaming', 'Tribal Casino and Hotel', 'Casino & Resort',
          'Gaming & Hospitality', 'Regional casino / racino', 'Casino Resort & Spa',
          'Commercial casino', 'Gaming', 'Racino', 'Tribal casino', 'Casino and lodge',
          'Sportsbook & casino', 'Bingo hall'] as $spelling) {
    $bucket($spelling, 'Casino');
}

$bucket('Healthcare', 'Healthcare');
$bucket('Critical-access hospital', 'Healthcare');
$bucket('Multi-location dental clinics', 'Healthcare');
$bucket('Senior living', 'Healthcare');
$bucket('Behavioral health', 'Healthcare');

$bucket('Higher Ed', 'Higher Ed');
$bucket('Community college', 'Higher Ed');
$bucket('Private university', 'Higher Ed');
$bucket('Technical college', 'Higher Ed');

$bucket('Ag', 'Ag');
$bucket('Agriculture', 'Ag');
$bucket('Seed company', 'Ag');
$bucket('Grain cooperative', 'Ag');
$bucket('Livestock genetics', 'Ag');

$bucket('Retail', 'Retail');
$bucket('Regional grocery chain', 'Retail');
$bucket('Sporting goods retailer', 'Retail');

// A farm and ranch STORE is a retailer, not an ag business. The ordering of
// the rules is what makes this come out right, so it is worth pinning.
$bucket('Farm & ranch store', 'Retail');

// The ones that must NOT be swept into a bucket. A lead filed under the wrong
// heading is harder to find than one filed under an odd heading, because
// nobody thinks to look there.
$bucket('Advertising agency', 'Advertising agency');
$bucket('Agency', 'Agency');
$bucket('Manufacturing', 'Manufacturing');
$bucket('Municipal government', 'Municipal government');
$bucket('Independent radio', 'Independent radio');
$bucket('', '');

// A tribal health clinic is healthcare, not a casino, even though tribal
// organisations are most of the casino list.
$bucket('Tribal health clinic', 'Healthcare');

// Other loops have no canonical set yet, so their values pass through.
$check(Verticals::canonical('Tribal gaming', 'partner') === 'Tribal gaming',
    'a loop with no canonical set leaves its verticals alone');
$check(Verticals::manages('client') === true && Verticals::manages('home') === false,
    'and says which loops it manages');

// What gets kept beside the heading.
$check(Verticals::detail('Tribal gaming', 'Casino') === 'Tribal gaming',
    'the original wording is kept when it says more than the heading');
$check(Verticals::detail('Casino', 'Casino') === '', 'and dropped when it says the same');
$check(Verticals::detail('Casinos', 'Casino') === '', 'including a plural of it');

// ------------------------------------------------ bucketing on the way in

$existing = (int) Database::scalar('SELECT COUNT(*) FROM leads');
if ($existing > 200) {
    fwrite(STDERR, "Refusing to run: {$existing} leads on file looks like real data.\n");
    exit(2);
}

Database::run('DELETE FROM leads');

// The client loop is Darren's. Find whoever runs it rather than assuming an id.
$darren = Database::first("SELECT id FROM users WHERE loop = 'client' LIMIT 1");

if ($darren === null) {
    fwrite(STDERR, "No client-loop user to test against.\n");
    exit(2);
}

$darrenId = (int) $darren['id'];
$runId = Runs::start($darrenId, 'client', Clock::today(), 'upload', 'Test', '', 'test');

$a = Leads::create($darrenId, $runId, ['company' => 'Wapiti Ridge', 'vertical' => 'Tribal gaming']);
$b = Leads::create($darrenId, $runId, ['company' => 'Prairie Winds Casino', 'vertical' => 'Casino & Resort']);
$c = Leads::create($darrenId, $runId, ['company' => 'Three Rivers Health', 'vertical' => 'Critical-access hospital']);
$d = Leads::create($darrenId, $runId, ['company' => 'Hartwell Mfg', 'vertical' => 'Precision manufacturing']);

$verticals = Database::all(
    'SELECT DISTINCT vertical FROM leads WHERE user_id = :u ORDER BY vertical',
    ['u' => $darrenId]
);
$list = array_column($verticals, 'vertical');
echo '       verticals on file: ' . implode(', ', $list) . "\n";

$check($list === ['Casino', 'Healthcare', 'Precision manufacturing'],
    'two spellings of casino become one heading, and the unknown one is left alone');

$check((string) Leads::find($a)['vertical_detail'] === 'Tribal gaming',
    'and what the list actually said is kept beside it');
// An unbucketed vertical is already its own wording in the vertical column,
// so there is nothing for the detail to add and it stays empty rather than
// saying the same thing twice.
$check((string) Leads::find($d)['vertical'] === 'Precision manufacturing'
    && (string) Leads::find($d)['vertical_detail'] === '',
    'an unbucketed vertical keeps its wording and stores no duplicate detail');

// ------------------------------------------- the rows that were already there

// New leads are bucketed on the way in, but a database that has been running
// for months is full of the spellings this exists to collapse. Those rows are
// written the old way here — straight to the table, bypassing the write path —
// because that is exactly how every row already in production got there.
$old = [];
foreach (['Tribal gaming', 'Casino & Resort', 'Casinos', 'Gaming & Hospitality',
          'Senior living', 'Precision manufacturing'] as $i => $spelling) {
    $old[] = Database::insert('leads', [
        'user_id' => $darrenId, 'run_id' => $runId,
        'company' => 'Legacy ' . $i, 'company_key' => 'legacy' . $i, 'contact_key' => 'legacy' . $i,
        'vertical' => $spelling, 'status' => 'new', 'fit_score' => 70,
        'created_at' => Clock::now(), 'updated_at' => Clock::now(),
    ]);
}

Settings::forget('verticals_normalised');
Schema::install();

$after = array_column(Database::all(
    'SELECT DISTINCT vertical FROM leads WHERE user_id = :u ORDER BY vertical',
    ['u' => $darrenId]
), 'vertical');

echo '       after the backfill: ' . implode(', ', $after) . "\n";
$check($after === ['Casino', 'Healthcare', 'Precision manufacturing'],
    'leads that were already on file get the same headings');
$check((string) Leads::find($old[0])['vertical_detail'] === 'Tribal gaming',
    'and keep what they originally said');
$check(Settings::get('verticals_normalised') !== '', 'the backfill records that it ran');

// It runs on every request until the flag is set, so a request that dies
// halfway must leave the rows it reached correct and converge on the next one.
$snapshot = Database::all('SELECT id, vertical, vertical_detail FROM leads ORDER BY id');
Schema::install();
$check(Database::all('SELECT id, vertical, vertical_detail FROM leads ORDER BY id') === $snapshot,
    'running it again changes nothing');

Settings::forget('verticals_normalised');
Schema::install();
$check(Database::all('SELECT id, vertical, vertical_detail FROM leads ORDER BY id') === $snapshot,
    'and running it again without the flag changes nothing either');

// Billy runs the partner loop, which has no canonical set — his verticals must
// not be touched by a rule written for Darren's.
$billy = Database::first("SELECT id FROM users WHERE loop = 'partner' LIMIT 1");
if ($billy !== null) {
    $billyRun = Runs::start((int) $billy['id'], 'partner', Clock::today(), 'upload', 'Test', '', 'test');
    $e = Leads::create((int) $billy['id'], $billyRun, ['company' => 'Red Cedar Radio', 'vertical' => 'Independent radio']);
    $check((string) Leads::find($e)['vertical'] === 'Independent radio',
        "another loop's verticals are left exactly as they are");
    Database::run('DELETE FROM runs WHERE id = :id', ['id' => $billyRun]);
}

// A re-upload that changes the vertical goes through the same bucketing, or
// the import preview would promise one heading and store another.
$changes = Leads::changesFor(Leads::find($c), ['company' => 'Three Rivers Health', 'vertical' => 'Regional medical center'], 'update');
$check($changes === [], 'a re-upload that renames a vertical into the same bucket is not a change');

$changes = Leads::changesFor(Leads::find($c), ['company' => 'Three Rivers Health', 'vertical' => 'Tribal gaming'], 'update');
$check(($changes['vertical']['to'] ?? '') === 'Casino',
    'and one that moves it to a different bucket lands as the heading, not the raw words');

// ------------------------------------------------------------ logging actions

$check(Leads::find($a)['last_action'] === null, 'a new lead has no action logged against it');

$check(Leads::logAction($a, 'called', 'Gatekeeper says Thursdays.', $darrenId) === true,
    'an action can be logged');
$check(Leads::logAction($a, 'not_a_real_action', null, $darrenId) === false,
    'and an invented one is refused');

$lead = Leads::find($a);
$check($lead['last_action'] === 'called', 'the lead carries the last action');
$check(trim((string) $lead['last_action_at']) !== '', 'and when it happened');

// The whole point: this happens more than once.
Leads::logAction($a, 'voicemail', null, $darrenId);
Leads::logAction($a, 'emailed', null, $darrenId);
Leads::logAction($a, 'met', 'Drove up for the quarterly.', $darrenId);

$logged = Database::all(
    "SELECT action, body FROM activities WHERE lead_id = :id AND type = 'action' ORDER BY id",
    ['id' => $a]
);
$check(count($logged) === 4, 'every action is kept, not just the most recent');
$check(array_column($logged, 'action') === ['called', 'voicemail', 'emailed', 'met'],
    'in the order they happened');
$check(Leads::find($a)['last_action'] === 'met', 'and the lead now shows the latest one');

$check(str_contains((string) $logged[0]['body'], 'Called'), 'the timeline entry names the action');
$check(str_contains((string) $logged[0]['body'], 'Gatekeeper'), 'and carries the note with it');
$check((string) $logged[1]['body'] === 'Left a voicemail', 'a note is optional');

// Logging is not a disposition. Having called someone is not having reached
// them, and quietly moving the status would put words in the caller's mouth.
$check(Leads::find($a)['status'] === 'new', 'logging an action does not change the disposition');

// Repeating the same action is a second entry, not an overwrite — three calls
// is the thing somebody needs to be able to see.
Leads::logAction($a, 'called', null, $darrenId);
$calls = (int) Database::scalar(
    "SELECT COUNT(*) FROM activities WHERE lead_id = :id AND action = 'called'",
    ['id' => $a]
);
$check($calls === 2, 'the same action logged twice is two entries');

// ------------------------------------------------------- how long ago it was

$check(Leads::sinceLabel(Clock::now()) === 'today', 'today reads as today, not 0d');
$check(Leads::sinceLabel(date('Y-m-d H:i:s', strtotime('-1 day'))) === 'yesterday', 'yesterday says so');
$check(Leads::sinceLabel(date('Y-m-d H:i:s', strtotime('-3 days'))) === '3d', 'a few days is days');
$check(Leads::sinceLabel(date('Y-m-d H:i:s', strtotime('-20 days'))) === '3w', 'a few weeks is weeks');
$check(Leads::sinceLabel(date('Y-m-d H:i:s', strtotime('-120 days'))) === '4mo', 'and longer is months');
$check(Leads::sinceLabel('') === '', 'nothing in gives nothing out');
$check(Leads::sinceLabel('not a date') === '', 'and so does nonsense');

// ------------------------------------------------------------- on the screen

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

$csrf = static function (string $html): string {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
};

// Sign in as an admin so the client-loop leads are visible from the list.
$login = $http('/login');
$http('/login', ['csrf' => $csrf($login), 'email' => 'scott@44interactive.com', 'password' => '44i123']);

$list = $http('/leads');
$check(str_contains($list, 'Log what you did…'), 'the list offers logging against a selection');
$check(str_contains($list, 'tag-action'), 'and tags the rows that have one');
$check(str_contains($list, 'Met in person'), 'naming the last action');

// One heading in the filter, not two.
// One heading in the filter rather than one per spelling. Matched on the
// option's value, since the visible text is laid out across several lines.
$check(substr_count($list, '<option value="Casino"') === 1,
    'the vertical filter offers Casino exactly once');
$check(!str_contains($list, '<option value="Tribal gaming"'),
    'and does not also offer the spellings it replaced');

$page = $http('/leads/' . $a);
$check(str_contains($page, 'Log what you did'), 'the lead screen has the log buttons');
$check(str_contains($page, 'Tribal gaming'), 'and still shows what the list originally called it');

// Log one through the screen rather than the class, so the route, the CSRF
// check and the form field names are all exercised.
$before = (int) Database::scalar("SELECT COUNT(*) FROM activities WHERE lead_id = :id AND type = 'action'", ['id' => $b]);
$http('/leads/' . $b . '/log', ['csrf' => $csrf($page), 'log_action' => 'texted', 'log_note' => 'Sent the deck.']);
$after = (int) Database::scalar("SELECT COUNT(*) FROM activities WHERE lead_id = :id AND type = 'action'", ['id' => $b]);

$check($after === $before + 1, 'posting the form logs an action');
$check(Leads::find($b)['last_action'] === 'texted', 'and the lead picks it up');

// And in bulk, which is how it actually happens — work down a list making
// calls, then tick the ones you got through to.
$page = $http('/leads');
$http('/leads/bulk', [
    'csrf' => $csrf($page), 'bulk_action' => 'log', 'log_action' => 'called',
    'ids[]' => $c, 'return' => '/leads',
]);
$check(Leads::find($c)['last_action'] === 'called', 'and a bulk log reaches the selected leads');

// A bulk log with nothing picked should say so rather than failing every row.
$page = $http('/leads');
$out = $http('/leads/bulk', ['csrf' => $csrf($page), 'bulk_action' => 'log', 'ids[]' => $d, 'return' => '/leads']);
$check(Leads::find($d)['last_action'] === null && str_contains($out, 'Pick what you did'),
    'and logging nothing is refused with a reason rather than counted as failures');

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
Database::run('DELETE FROM runs WHERE id = :id', ['id' => $runId]);
@unlink($jar);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
