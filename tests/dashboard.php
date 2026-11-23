<?php

declare(strict_types=1);

/**
 * What Home is built from: the queue, the week's numbers, the next seven
 * days, and the funnel.
 *
 *     php tests/dashboard.php
 *
 * Each seeded lead is built to land in exactly one place — or nowhere — so a
 * lead showing up twice, or slipping through every section, fails here.
 *
 * Local only. It writes leads, activities and reminders and removes them.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Dashboard;
use Prospector\Leads;
use Prospector\Reminders;
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

if ((int) Database::scalar('SELECT COUNT(*) FROM leads') > 200) {
    fwrite(STDERR, "Refusing to run: this looks like real data.\n");
    exit(2);
}

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');
Database::run('DELETE FROM activities');

$darren = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")['id'];
$billy = (int) Database::first("SELECT id FROM users WHERE loop = 'partner'")['id'];
$run = Runs::start($darren, 'client', Clock::today(), 'upload', 'Dashboard test', '', 'test');
$billyRun = Runs::start($billy, 'partner', Clock::today(), 'upload', 'Dashboard test', '', 'test');

$ago = static fn (string $spec): string => gmdate('Y-m-d H:i:s', strtotime('-' . $spec));
$ahead = static fn (string $spec): string => gmdate('Y-m-d H:i:s', strtotime('+' . $spec));

$lead = static function (string $company, array $set = [], ?int $owner = null) use ($darren, $run, $billyRun): int {
    $owner ??= $darren;
    $id = Leads::create($owner, $owner === $darren ? $run : $billyRun, [
        'company' => $company, 'decision_maker' => $company . ' Contact', 'fit_score' => $set['fit_score'] ?? 70,
    ]);
    unset($set['fit_score']);
    if ($set !== []) {
        Database::update('leads', $set, ['id' => $id]);
    }

    return $id;
};

// ------------------------------------------------- the queue

$due = $lead('Due Co');
Reminders::create($due, $darren, $ago('1 hour'), 'Call back after the board meeting');

$stalled = $lead('Stalled Co', ['last_action' => 'called', 'last_action_at' => $ago('3 days')]);
$tooSoon = $lead('Too Soon Co', ['last_action' => 'voicemail', 'last_action_at' => $ago('1 day')]);
$cold = $lead('Cold Co', ['status' => 'contacted', 'last_action' => 'called', 'last_action_at' => $ago('12 days')]);
$coldMeeting = $lead('Cold Meeting Co', ['status' => 'meeting', 'last_action' => 'met', 'last_action_at' => $ago('15 days')]);
$freshBest = $lead('Fresh Best Co', ['fit_score' => 95]);
$freshOther = $lead('Fresh Other Co', ['fit_score' => 60]);
$scheduled = $lead('Scheduled Co', ['last_action' => 'called', 'last_action_at' => $ago('3 days')]);
Reminders::create($scheduled, $darren, $ahead('2 days'));
$signed = $lead('Signed Co', ['status' => 'signed', 'last_action' => 'called', 'last_action_at' => $ago('3 days')]);
$archived = $lead('Archived Co', ['last_action' => 'called', 'last_action_at' => $ago('3 days'), 'archived_at' => $ago('1 hour')]);
$billys = $lead('Billys Co', ['last_action' => 'called', 'last_action_at' => $ago('3 days')], $billy);

$queue = Dashboard::queue($darren);
$names = static fn (array $rows): array => array_map(static fn ($r) => $r['company'], $rows);

echo '       due: ' . implode(', ', $names($queue['due'])) . "\n";
echo '       stalled: ' . implode(', ', $names($queue['stalled'])) . "\n";
echo '       cold: ' . implode(', ', $names($queue['cold'])) . "\n";
echo '       fresh: ' . implode(', ', $names($queue['fresh'])) . "\n";

$check($names($queue['due']) === ['Due Co'], 'a follow-up that has come due is at the top');
$check(($queue['due'][0]['reminder_note'] ?? '') === 'Call back after the board meeting', 'carrying its note');
$check($names($queue['stalled']) === ['Stalled Co'], 'called three days ago and nothing since is "nothing since"');
$check($names($queue['cold']) === ['Cold Meeting Co', 'Cold Co'] || $names($queue['cold']) === ['Cold Co', 'Cold Meeting Co'],
    'twelve days without a word is going cold — a booked meeting included');
$check($names($queue['fresh']) === ['Fresh Best Co', 'Fresh Other Co'], 'untouched leads come best fit first');

$everywhere = array_merge(...array_values(array_map($names, $queue)));
$check(count($everywhere) === count(array_unique($everywhere)), 'no lead is in two sections');
$check(!in_array('Too Soon Co', $everywhere, true), 'a voicemail yesterday is not chased yet');
$check(!in_array('Scheduled Co', $everywhere, true), 'a lead with a follow-up already scheduled is left to it');
$check(!in_array('Signed Co', $everywhere, true), 'a signed lead is not chased');
$check(!in_array('Archived Co', $everywhere, true), 'nor an archived one');
$check(!in_array('Billys Co', $everywhere, true), "nor somebody else's");

$team = Dashboard::queue(null);
$check(in_array('Billys Co', $names($team['stalled']), true), 'the team view has everybody\'s');

// A called-then-ignored lead does not drop out of everything after ten days.
Database::update('leads', ['last_action_at' => $ago('11 days')], ['id' => $stalled]);
$check(in_array('Stalled Co', $names(Dashboard::queue($darren)['cold']), true),
    'and "nothing since" turns into "going cold" rather than vanishing');
Database::update('leads', ['last_action_at' => $ago('3 days')], ['id' => $stalled]);

// A follow-up ticked off with nothing logged must not put the lead back
// under "fresh and untouched" — someone has plainly been working it.
$ticked = $lead('Ticked Off Co');
$tickedReminder = Reminders::create($ticked, $darren, $ago('1 hour'));
Reminders::done($tickedReminder);
$everywhere = array_merge(...array_values(array_map($names, Dashboard::queue($darren))));
$check(!in_array('Ticked Off Co', $everywhere, true),
    'a lead whose follow-up was ticked off does not reappear as fresh');

// ------------------------------------------------- following up

Leads::logAction($due, 'called', null, $darren);
$check(!in_array('Due Co', $names(Dashboard::queue($darren)['due']), true),
    'logging a call on a due follow-up takes it off the queue');

// ------------------------------------------------- the week

Database::run('DELETE FROM activities');
$act = static function (int $leadId, string $type, ?string $action, string $body, string $when) use ($darren): void {
    Database::insert('activities', ['lead_id' => $leadId, 'user_id' => $darren, 'type' => $type,
        'action' => $action, 'body' => $body, 'created_at' => $when]);
};

$now = Clock::now();
$act($stalled, 'action', 'called', 'Called', $now);
$act($stalled, 'action', 'voicemail', 'Left a voicemail', $now);
$act($cold, 'action', 'emailed', 'Emailed', $now);
$act($cold, 'email', null, 'Emailed: Quick question', $now);                     // sent from Prospector
$act($cold, 'note', null, 'Texted: Are you around Thursday?', $now);             // a text from Prospector
$act($coldMeeting, 'status', 'status:meeting', 'Status set to Meeting booked', $now);
$act($freshBest, 'status', null, 'Status set to Meeting booked — Tuesday 2pm', $now); // recorded before the column
$act($signed, 'status', 'status:signed', 'Status set to Signed', $now);
$act($stalled, 'note', null, 'Spoke to reception', $now);                        // a note is not a touch
$act($stalled, 'action', 'called', 'Called', $ago('7 days'));                    // last week

$board = Dashboard::scoreboard($darren);
echo '       this week: ' . json_encode($board['this']) . "\n";

$check($board['this']['calls'] === 2, 'calls this week count calls and voicemails');
$check($board['this']['emails'] === 2, 'emails count logged ones and ones sent from Prospector');
$check($board['this']['meetings'] === 2, 'meetings booked count both new and older status records');
$check($board['this']['wins'] === 1, 'and wins');
$check($board['this']['touches'] === 5, 'touches count every action and send, not notes or status changes');
$check($board['last']['calls'] === 1, 'last week is counted separately, for comparison');
$check(count($board['days']) === 7 && $board['days'][0]['label'] === 'Mon', 'the week runs Monday to Sunday');

$todayBar = array_values(array_filter($board['days'], static fn ($d) => $d['today']))[0] ?? null;
$check($todayBar !== null && $todayBar['count'] === 5, "today's bar holds today's touches");
$check($board['goal'] === 25, 'a seller\'s weekly goal is 25 touches by default');
$check(Dashboard::scoreboard(null)['goal'] > 25, 'the team\'s goal is everybody\'s added up');

// ------------------------------------------------- the next seven days

Database::run('DELETE FROM reminders');
$tomorrow9 = Reminders::fromLocal(Clock::local()->modify('+1 day')->format('Y-m-d'), '09:00');
$inThree = Reminders::fromLocal(Clock::local()->modify('+3 days')->format('Y-m-d'), '14:30');
$tooFar = Reminders::fromLocal(Clock::local()->modify('+9 days')->format('Y-m-d'), '09:00');
Reminders::create($stalled, $darren, $tomorrow9, 'Second try');
Reminders::create($cold, $darren, $inThree);
Reminders::create($freshBest, $darren, $tooFar);
Reminders::create($billys, $billy, $tomorrow9);

$strip = Dashboard::upcoming($darren);
$check(count($strip) === 7 && $strip[0]['label'] === 'Today' && $strip[1]['label'] === 'Tomorrow',
    'the strip is seven days, starting today');
$check(count($strip[1]['items']) === 1 && $strip[1]['items'][0]['company'] === 'Stalled Co' && $strip[1]['items'][0]['time'] === '9:00am',
    'a follow-up tomorrow at 9 sits under Tomorrow, with its time');
$check(count($strip[3]['items']) === 1 && $strip[3]['items'][0]['time'] === '2:30pm', 'one in three days sits three columns on');
$check(array_sum(array_map(static fn ($d) => count($d['items']), $strip)) === 2,
    'one more than a week out is not on it, and nor is Billy\'s');

// ------------------------------------------------- the funnel

Database::run('DELETE FROM leads');
foreach (['new', 'new', 'new', 'contacted', 'contacted', 'no_answer', 'meeting', 'meeting', 'signed', 'disqualified'] as $i => $status) {
    $lead('Funnel ' . $i, ['status' => $status]);
}
$lead('Called but still new', ['last_action' => 'called', 'last_action_at' => $ago('1 hour')]);

$funnel = array_column(Dashboard::funnel($darren), null, 'key');
echo '       funnel: ' . implode(' → ', array_map(static fn ($s) => $s['label'] . ' ' . $s['count'] . ($s['rate'] !== null ? " ({$s['rate']}%)" : ''), $funnel)) . "\n";

$check($funnel['leads']['count'] === 10, 'the funnel starts from every lead but the disqualified');
$check($funnel['reached']['count'] === 7, 'reached out includes a lead called but never given a status');
$check($funnel['meeting']['count'] === 3, 'meetings include the ones that went on to sign');
$check($funnel['signed']['count'] === 1, 'and signed is signed');
$check($funnel['reached']['rate'] === 70 && $funnel['meeting']['rate'] === 43 && $funnel['signed']['rate'] === 33,
    'each step shows the rate from the one before');
$check($funnel['reached']['link'] === ['contacted' => '1'], 'and links to those leads');

// ------------------------------------------------- what to suggest next

$local = static fn (string $when): DateTimeImmutable => new DateTimeImmutable($when, Clock::timezone());

$after = Reminders::followUpFor('voicemail', $local('2026-11-26 15:00')); // a Thursday
$check(($after['date'] ?? '') === '2026-11-30' && ($after['label'] ?? '') === 'Monday, 9am',
    'a voicemail on Thursday suggests Monday — two working days, over the weekend');

$after = Reminders::followUpFor('emailed', $local('2026-11-23 10:00')); // a Monday
$check(($after['date'] ?? '') === '2026-11-26' && ($after['label'] ?? '') === 'Thursday, 9am', 'an email on Monday suggests Thursday');

$after = Reminders::followUpFor('met', $local('2026-11-23 10:00'));
$check(($after['date'] ?? '') === '2026-11-30' && ($after['label'] ?? '') === 'Mon Nov 30, 9am',
    'a meeting suggests a week on, with the date once a weekday name would be ambiguous');

$check(Reminders::followUpFor('not_an_action') === null, 'nothing is suggested for something that is not an action');

$snoozeMe = Reminders::create($stalled = $lead('Snooze Co'), $darren, $ago('1 hour'));
Database::run('UPDATE reminders SET sent_at = :now WHERE id = :id', ['now' => Clock::now(), 'id' => $snoozeMe]);
$newDue = Reminders::snooze($snoozeMe, 2);
$row = Reminders::find($snoozeMe);
$check($row['due_at'] === $newDue && strtotime($newDue) > time() && $row['sent_at'] === null,
    'snoozing moves a follow-up two working days on, and it will be emailed again then');
$check(Clock::display($newDue, 'g:ia') === '9:00am', 'at 9am');

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');
Database::run('DELETE FROM activities');
Database::run("DELETE FROM runs WHERE vertical = 'Dashboard test'");

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
