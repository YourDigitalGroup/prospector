<?php

declare(strict_types=1);

/**
 * Follow-up reminders and "who have I contacted".
 *
 *     php tests/reminders.php
 *
 * No server and no mail server: the reminder sweep takes its sender as a
 * parameter, so this hands it one that records what would have gone out.
 *
 * Local only. It writes leads and reminders and removes them afterwards.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\Leads;
use Prospector\Reminders;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\Database;
use Prospector\Support\Schema;
use Prospector\Support\Settings;

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

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');

$darren = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")['id'];
$scott = (int) Database::first("SELECT id FROM users WHERE role = 'admin'")['id'];
$runId = Runs::start($darren, 'client', Clock::today(), 'upload', 'Reminder test', '', 'test');

$lead = static fn (string $company, array $extra = []): int =>
    Leads::create($darren, $runId, ['company' => $company, 'decision_maker' => 'Dana Whitfield'] + $extra);

// Timestamps relative to now, in the stored UTC format.
$ago = static fn (string $spec): string => gmdate('Y-m-d H:i:s', strtotime('-' . $spec));
$ahead = static fn (string $spec): string => gmdate('Y-m-d H:i:s', strtotime('+' . $spec));

// A sender that records rather than sends.
$outbox = [];
$capture = static function (string $to, string $name, string $subject, string $html) use (&$outbox): array {
    $outbox[] = compact('to', 'name', 'subject', 'html');

    return ['ok' => true, 'message' => 'captured'];
};

// ------------------------------------------------------- reading the time

// What a person types is Sioux Falls time. Stored is UTC. Daylight saving ended
// on 1 November 2026, so the same 9am is a different UTC hour either side.
$check(Reminders::fromLocal('2026-10-30', '09:00') === '2026-10-30 14:00:00',
    '9am on a CDT day is stored as 14:00 UTC');
$check(Reminders::fromLocal('2026-11-05', '09:00') === '2026-11-05 15:00:00',
    'and 9am after the clocks went back is 15:00 UTC — the offset follows the date, not today');
$check(Reminders::fromLocal('2026-11-05', '14:30') === '2026-11-05 20:30:00', 'an afternoon time lands too');
$check(Reminders::fromLocal('2026-11-05', '') === '2026-11-05 15:00:00', 'a day with no time means 9am');
$check(Reminders::fromLocal('', '09:00') === null, 'no day is refused');
$check(Reminders::fromLocal('next tuesday', '09:00') === null, 'and so is nonsense');

$shown = Clock::display(Reminders::fromLocal('2026-11-05', '14:30'), 'D M j, g:ia');
$check($shown === 'Thu Nov 5, 2:30pm', "and it reads back as it was typed — {$shown}");

// ------------------------------------------------------- the one-tap presets

$at = static fn (string $local): DateTimeImmutable => new DateTimeImmutable($local, Clock::timezone());

$tuesday = Reminders::presets($at('2026-11-03 16:00'));
$check($tuesday['tomorrow']['date'] === '2026-11-04', 'Tuesday: "tomorrow" is Wednesday');
$check($tuesday['two_days']['date'] === '2026-11-05' && $tuesday['two_days']['label'] === 'Thursday, 9am',
    'two days on is Thursday, and says so');
$check($tuesday['next_week']['date'] === '2026-11-10', 'next week is the following Tuesday');
$check($tuesday['tomorrow']['time'] === '09:00', 'all at 9am');

$friday = Reminders::presets($at('2026-11-06 16:00'));
$check($friday['tomorrow']['date'] === '2026-11-09',
    'Friday: "tomorrow" steps over the weekend to Monday');
$check($friday['two_days']['date'] === '2026-11-09', 'and so does two days on, which would be Sunday');

$thursday = Reminders::presets($at('2026-11-05 16:00'));
$check($thursday['two_days']['date'] === '2026-11-09', 'Thursday: two days on is Saturday, so Monday');

// ------------------------------------------------------- setting and sending

$a = $lead('Alpha Co', ['email' => 'dana@alpha.example.com', 'phone' => '(605) 555-0101']);
$b = $lead('Beta Co');
$c = $lead('Gamma Co');

$dueNow = Reminders::create($a, $darren, $ago('5 minutes'), 'Call back after the board meeting');
$later = Reminders::create($b, $darren, $ahead('2 days'));
$doneAlready = Reminders::create($c, $darren, $ago('1 hour'));
Reminders::done($doneAlready);

$check($dueNow > 0 && $later > 0, 'reminders can be set');

$timeline = Database::all("SELECT body FROM activities WHERE lead_id = :id AND type = 'reminder'", ['id' => $a]);
$check(count($timeline) === 1 && str_contains($timeline[0]['body'], 'Call back after'),
    'and setting one is written to the lead\'s timeline with its note');

$result = Reminders::sendDue(25, $capture);

$check($result['sent'] === 1 && count($outbox) === 1, 'the sweep sends exactly the one that is due');
$check(($outbox[0]['to'] ?? '') === 'darren@44i.com', 'to the person who set it');
$check(str_contains($outbox[0]['subject'] ?? '', 'Alpha Co') && str_contains($outbox[0]['subject'] ?? '', 'Dana Whitfield'),
    'naming the company and the contact in the subject — ' . ($outbox[0]['subject'] ?? ''));
$check(str_contains($outbox[0]['html'] ?? '', 'Call back after the board meeting'), 'carrying the note');
$check(str_contains($outbox[0]['html'] ?? '', '(605) 555-0101'), 'and the number to call');
$check(str_contains($outbox[0]['html'] ?? '', '/leads/' . $a), 'and a link straight to the lead');

// The duplicate-send guard. Two sweeps overlapping — the webhook and a page
// load in the same minute — must not email the same reminder twice.
$outbox = [];
Reminders::sendDue(25, $capture);
$check($outbox === [], 'a second sweep does not send it again');

// A claim that lost the race sends nothing. Simulated by claiming the row
// first, the way a concurrent sweep would.
$raced = Reminders::create($b, $darren, $ago('1 minute'));
Database::run('UPDATE reminders SET sent_at = :now WHERE id = :id', ['now' => Clock::now(), 'id' => $raced]);
$outbox = [];
Reminders::sendDue(25, $capture);
$check($outbox === [], 'a reminder another sweep has already claimed is left to it');

$check(count(array_filter([$later], static fn ($id) => Reminders::find($id)['sent_at'] === null)) === 1,
    'one not yet due is not touched');

// ------------------------------------------------------- when sending fails

Database::run('DELETE FROM reminders');
$failing = Reminders::create($a, $darren, $ago('2 minutes'));
$refuse = static fn (): array => ['ok' => false, 'message' => 'SMTP said no'];

$first = Reminders::sendDue(25, $refuse);
$row = Reminders::find($failing);
$check($first['failed'] === 1 && $row['sent_at'] === null && $row['error'] === 'SMTP said no',
    'a failed send is released for the next sweep, with the reason kept');

Reminders::sendDue(25, $refuse);
Reminders::sendDue(25, $refuse);
$outbox = [];
Reminders::sendDue(25, $capture);
$check($outbox === [] && (int) Reminders::find($failing)['attempts'] === 3,
    'and given up on after three attempts rather than retried forever');
$check(Reminders::dueCount($darren) === 1,
    'but it still shows as due in the app — a lost email does not lose the reminder');

// ------------------------------------------------------- dealt with

Database::run('DELETE FROM reminders');
$due = Reminders::create($a, $darren, $ago('10 minutes'));
$future = Reminders::create($a, $darren, $ahead('3 days'));
$scotts = Reminders::create($a, $scott, $ago('10 minutes'));

$check(Reminders::dueCount($darren) === 1, 'the top bar counts what has come due for that person');
$check(Reminders::dueCount($scott) === 1, 'and each person sees their own');

// Following up is what the reminder asked for.
Leads::logAction($a, 'called', 'Left a voicemail', $darren);

$check(Reminders::find($due)['done_at'] !== null, 'logging a call after a reminder came due marks it done');
$check(Reminders::find($future)['done_at'] === null, 'but not one set for next week');
$check(Reminders::find($scotts)['done_at'] === null, "and not somebody else's");
$check(Reminders::dueCount($darren) === 0, 'so the top bar clears');

// Archiving a lead stops its reminders nagging.
$archivedDue = Reminders::create($b, $darren, $ago('1 minute'));
Leads::archive($b, $darren);
$outbox = [];
Reminders::sendDue(25, $capture);
// Scott's own overdue reminder on Alpha Co goes out in this sweep, rightly —
// what matters is that nothing goes out about the archived lead.
$aboutBeta = array_filter($outbox, static fn ($m) => str_contains($m['subject'], 'Beta Co'));
$check($aboutBeta === [] && Reminders::dueCount($darren) === 0,
    'an archived lead\'s reminder is neither emailed nor counted');
Leads::restore($b, $darren);

Leads::delete($c);
$check((int) Database::scalar('SELECT COUNT(*) FROM reminders WHERE lead_id = :id', ['id' => $c]) === 0,
    'deleting a lead takes its reminders with it');

// ------------------------------------------------------- the list column

// Alpha Co has Darren's reminder for next week and an overdue one Scott set for
// himself. Darren's list must show Darren's — "Follow up now" on his own list
// because of a reminder that is not his would send him chasing nothing.
$listed = array_column(Leads::search(['user_id' => $darren, 'viewer_id' => $darren], 50), null, 'company');
$check(($listed['Alpha Co']['next_reminder_at'] ?? null) === Reminders::find($future)['due_at'],
    'the list shows the viewer\'s own next reminder, not someone else\'s overdue one');

$scottsView = array_column(Leads::search(['viewer_id' => $scott], 50), null, 'company');
$check(($scottsView['Alpha Co']['next_reminder_at'] ?? null) === Reminders::find($scotts)['due_at'],
    'and Scott, looking at the same lead, sees his');
$check(($listed['Beta Co']['next_reminder_at'] ?? 'x') === Reminders::find($archivedDue)['due_at'],
    'including an overdue one');

// ------------------------------------------------------- contacted

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');

$called = $lead('Called Co');
$emailedHere = $lead('Emailed From Prospector');
$meeting = $lead('Meeting Booked Co');
$disqualified = $lead('Disqualified Co');
$untouched = $lead('Untouched Co');

Leads::logAction($called, 'called', null, $darren);
Leads::touch($emailedHere, 'emailed');
Leads::setStatus($meeting, 'meeting', null, $darren);
Leads::setStatus($disqualified, 'disqualified', null, $darren);

$names = static fn (array $filters): array =>
    array_values(array_map(static fn ($r) => $r['company'], Leads::search($filters + ['sort' => 'company'], 50)));

$check($names(['contacted' => '1']) === ['Called Co', 'Emailed From Prospector', 'Meeting Booked Co'],
    'Contacted: a logged call, an email sent from Prospector, and a booked meeting');
$check($names(['contacted' => '0']) === ['Disqualified Co', 'Untouched Co'],
    'Not yet contacted: the untouched, and one disqualified from research alone');
$check(count($names([])) === 5, 'and with neither, everyone');

// An email sent from inside the app is an email.
$check(Leads::find($emailedHere)['last_action'] === 'emailed', 'a send from Prospector sets the Emailed tag');

// The last action never runs backwards — a backfill of an old email must not
// hide a call made since.
Leads::touch($called, 'emailed', $ago('30 days'));
$check(Leads::find($called)['last_action'] === 'called', 'an older event does not replace a newer one');

// ------------------------------------------------------- backfilling old sends

// Emails and texts sent from Prospector before this left a timeline entry but
// no last action. They are found again from the timeline, once.
$old = $lead('Emailed Last Month');
$texted = $lead('Texted Last Week');
Database::insert('activities', ['lead_id' => $old, 'user_id' => $darren, 'type' => 'email',
    'body' => 'Emailed: Quick question', 'created_at' => $ago('30 days')]);
Database::insert('activities', ['lead_id' => $texted, 'user_id' => $darren, 'type' => 'note',
    'body' => 'Texted: Are you around Thursday?', 'created_at' => $ago('7 days')]);
Database::insert('activities', ['lead_id' => $untouched, 'user_id' => $darren, 'type' => 'note',
    'body' => 'Spoke to the receptionist', 'created_at' => $ago('2 days')]);

Settings::forget('sent_actions_backfilled');
Schema::install();

$check(Leads::find($old)['last_action'] === 'emailed', 'an email sent from Prospector last month is found');
$check(Leads::find($old)['last_action_at'] === $ago('30 days') || abs(strtotime(Leads::find($old)['last_action_at']) - strtotime($ago('30 days'))) < 5,
    'dated when it was sent, not today');
$check(Leads::find($texted)['last_action'] === 'texted', 'and so is a text');
$check(Leads::find($untouched)['last_action'] === null, 'but an ordinary note is not mistaken for contact');
$check(Leads::find($called)['last_action'] === 'called', 'and nothing already logged is overwritten');

// ------------------------------------------------- the page-load backstop

// Runs at the end of a page load, after the response has gone, wherever the
// host can finish a response early. The dev server cannot, so it is checked in
// a child process that provides the function production hosting has, with mail
// pointed at a transport that fails fast — what is being checked is that the
// sweep ran, which a recorded attempt shows.
Database::run('DELETE FROM reminders');
$backstopLead = $lead('Backstop Co');
$backstop = Reminders::create($backstopLead, $darren, $ago('1 minute'));
Settings::forget('reminders_swept_at');

$child = static function (): string {
    $code = 'function fastcgi_finish_request() { echo "[finished]"; return true; }'
        . ' require "app/bootstrap.php";'
        . ' Prospector\\Support\\Settings::set("mail_transport", "smtp");'
        . ' Prospector\\Support\\Settings::set("smtp_host", "127.0.0.1");'
        . ' Prospector\\Support\\Settings::set("smtp_port", "1");'
        . ' Prospector\\Reminders::sweepAfterResponse();'
        . ' echo "[page]";';

    return (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php -r ' . escapeshellarg($code) . ' 2>&1');
};

$transport = Settings::get('mail_transport');
$smtpHost = Settings::get('smtp_host');
$smtpPort = Settings::get('smtp_port');

$output = $child();
$row = Reminders::find($backstop);

$check(str_starts_with($output, '[page][finished]'),
    'the page is sent first and the response finished before any mail is attempted');
$check((int) $row['attempts'] === 1, 'then the due reminder is attempted');
$check($row['sent_at'] === null && (string) $row['error'] !== '',
    'and a mail server that refuses it leaves it waiting for the next try, with the reason');

// Once a minute at most, however many pages are loaded.
$child();
$check((int) Reminders::find($backstop)['attempts'] === 1,
    'a second page load within the minute does not try again');

// And a page load with nothing due does not finish the response early at all.
Database::run('UPDATE reminders SET done_at = :now', ['now' => Clock::now()]);
Settings::forget('reminders_swept_at');
$check(!str_contains($child(), '[finished]'), 'a page load with nothing due is left alone');

Settings::set('mail_transport', $transport);
Settings::set('smtp_host', $smtpHost);
Settings::set('smtp_port', $smtpPort);
Settings::forget('reminders_swept_at');

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
Database::run('DELETE FROM reminders');
Database::run('DELETE FROM runs WHERE id = :id', ['id' => $runId]);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
