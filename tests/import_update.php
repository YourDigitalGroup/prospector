<?php

declare(strict_types=1);

/**
 * Re-uploading a list: matching, change detection, and what must not move.
 *
 *     php tests/import_update.php
 *
 * No browser and no network — this is the logic that decides whether a row
 * becomes a new lead or edits one you have been working, which is the part
 * where being wrong is expensive. The screen is checked separately.
 *
 * Runs against the configured database and cleans up after itself, so it is
 * safe to run locally. It refuses to run against anything holding real volume.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\Database;

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

// A test that quietly wipes a real database would be a worse bug than any it
// could find.
$existing = (int) Database::scalar('SELECT COUNT(*) FROM leads');

if ($existing > 200) {
    fwrite(STDERR, "Refusing to run: {$existing} leads on file looks like real data.\n");
    exit(2);
}

Database::run('DELETE FROM leads');

$userId = 1;
$runId = Runs::start($userId, 'partner', Clock::today(), 'upload', 'Test', '', 'test');

/** @param array<string, mixed> $lead */
$seed = static function (array $lead) use ($userId, $runId): int {
    return Leads::create($userId, $runId, $lead);
};

// ---------------------------------------------------------------- matching

$id = $seed([
    'company' => 'Prairie Sky Radio',
    'decision_maker' => 'Dana Whitfield',
    'phone' => '(712) 555-0142',
    'fit_score' => 70,
]);

$check($id > 0, 'a lead can be seeded');

// The case this whole feature exists for: the list has since found an address.
// The stored lead is keyed on the name, the incoming row keys on the email, so
// a key-only match would miss and file a second copy of the same person.
$withEmail = [
    'company' => 'Prairie Sky Radio',
    'decision_maker' => 'Dana Whitfield',
    'email' => 'dana@prairieskyradio.example.com',
];

$match = Leads::matchFor($userId, $withEmail);
$check($match !== null && (int) $match['id'] === $id,
    'a row that has gained an email still matches the lead it belongs to');

// And the reverse: stored with an address, re-uploaded without one.
$named = Leads::matchFor($userId, ['company' => 'Prairie Sky Radio', 'decision_maker' => 'Dana Whitfield']);
$check($named !== null && (int) $named['id'] === $id, 'and so does one that has lost it');

$check(Leads::matchFor($userId, ['company' => 'Prairie Sky Radio', 'decision_maker' => 'Someone Else']) === null,
    'a different person at the same company is not a match');
$check(Leads::matchFor($userId, ['company' => 'Somewhere Else', 'decision_maker' => 'Dana Whitfield']) === null,
    'nor the same person at a different company');
$check(Leads::matchFor(2, $withEmail) === null, 'nor the same person on somebody else\'s list');

// ------------------------------------------------------------ what changes

$lead = Leads::find($id);

$changes = Leads::changesFor($lead, $withEmail, 'update');
$check(isset($changes['email']) && $changes['email']['to'] === 'dana@prairieskyradio.example.com',
    'a new address is picked up as a change');
$check($changes['email']['from'] === '', 'and reported as coming from nothing');
$check(!isset($changes['decision_maker']), 'a field the file repeats unchanged is not a change');

// The rule that keeps an upload from being destructive.
$sparse = ['company' => 'Prairie Sky Radio', 'decision_maker' => 'Dana Whitfield', 'email' => 'dana@x.example.com'];
$changes = Leads::changesFor($lead, $sparse, 'update');
$check(!isset($changes['phone']),
    'a column the file does not carry never clears what is on file');
$check(!isset($changes['fit_score']),
    'and a blank score never overwrites a real one');

// fit_score is 0 for unscored, so 0 has to read as "nothing said".
$zero = ['company' => 'Prairie Sky Radio', 'decision_maker' => 'Dana Whitfield', 'fit_score' => 0];
$check(!isset(Leads::changesFor($lead, $zero, 'update')['fit_score']),
    'a zero score is treated as nothing said rather than as a score of zero');

$better = ['company' => 'Prairie Sky Radio', 'decision_maker' => 'Dana Whitfield', 'fit_score' => 91];
$check((Leads::changesFor($lead, $better, 'update')['fit_score']['to'] ?? '') === '91',
    'a real score does land');

// ------------------------------------------------------------------- modes

$conflict = [
    'company' => 'Prairie Sky Radio',
    'decision_maker' => 'Dana Whitfield',
    'phone' => '(712) 555-9999',
    'email' => 'dana@prairieskyradio.example.com',
];

$update = Leads::changesFor($lead, $conflict, 'update');
$fill = Leads::changesFor($lead, $conflict, 'fill');
$skip = Leads::changesFor($lead, $conflict, 'skip');

$check(isset($update['phone']), 'update mode replaces a phone number that is already there');
$check(!isset($fill['phone']), 'fill mode leaves it alone');
$check(isset($fill['email']), 'but fill mode still fills the blank address');
$check($skip === [], 'skip mode changes nothing at all');

// ------------------------------------------------------------- applying it

$moved = Leads::applyChanges($id, $update, $userId);
$after = Leads::find($id);

$check($moved === count($update), 'applying reports how many fields moved');
$check($after['email'] === 'dana@prairieskyradio.example.com', 'the address is written');
$check($after['phone'] === '(712) 555-9999', 'and the phone number');
$check($after['contact_key'] === 'dana@prairieskyradio.example.com',
    'the de-duplication key moves with the address, or matching would go on using the old one');

$timeline = Leads::activities($id);
$note = '';
foreach ($timeline as $entry) {
    if (str_contains((string) $entry['body'], 'Updated from')) {
        $note = (string) $entry['body'];
        break;
    }
}
echo "       timeline: {$note}\n";
$check(str_contains($note, 'email set to'), 'the change is written to the timeline, naming the field');
$check(str_contains($note, '→'), 'and shows what a replaced value was');

// ------------------------------------------------- what an upload may not do

Leads::setStatus($id, 'signed', 'Closed on the call', $userId);
Leads::markSyncedToGhl($id, 'ghl_abc123');
Leads::markOpened($id, $userId);

$before = Leads::find($id);

$hostile = [
    'company' => 'Prairie Sky Radio',
    'decision_maker' => 'Dana Whitfield',
    'status' => 'new',
    'owner_note' => 'wiped',
    'ghl_contact_id' => '',
    'opened_at' => '',
    'archived_at' => '',
    'created_at' => '2001-01-01',
    'title' => 'General Manager',
];

$changes = Leads::changesFor($before, $hostile, 'update');
echo '       fields a hostile row could move: ' . implode(', ', array_keys($changes)) . "\n";
$check(array_keys($changes) === ['title'], 'a row carrying extra columns can only move the ones an upload owns');

Leads::applyChanges($id, $changes, $userId);
$final = Leads::find($id);

$check($final['status'] === 'signed', 'a disposition survives an upload');
$check($final['owner_note'] === 'Closed on the call', 'so does the note on it');
$check($final['ghl_contact_id'] === 'ghl_abc123', 'so does the GoHighLevel link');
$check($final['opened_at'] !== null, 'and the batch does not go back to looking unopened');
$check($final['created_at'] === $before['created_at'], 'and it keeps the day it arrived');
$check($final['title'] === 'General Manager', 'while the field the file did own is updated');

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
Database::run('DELETE FROM runs WHERE id = :id', ['id' => $runId]);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
