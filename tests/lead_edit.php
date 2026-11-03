<?php

declare(strict_types=1);

/**
 * Editing a lead by hand.
 *
 *     php bin/serve.php &
 *     php tests/lead_edit.php
 *
 * The rules first, straight against Leads::edit; then the screen, over HTTP,
 * signed in as Darren — the person who asked to be able to fix a name — and
 * as Billy, who must not be able to edit Darren's leads.
 *
 * Local only. It signs in with the seeded dev accounts and writes leads.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Prospector\LeadForm;
use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\Database;

$base = rtrim(getenv('PROSPECTOR_TEST_URL') ?: 'http://127.0.0.1:8099', '/');

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

$darren = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")['id'];
$runId = Runs::start($darren, 'client', Clock::today(), 'upload', 'Edit test', '', 'test');

$id = Leads::create($darren, $runId, [
    'company' => 'Wapiti Ridge Casino',
    'vertical' => 'Tribal gaming',
    'decision_maker' => 'darren',
    'title' => 'Marketing',
    'email' => 'wrong@example.com',
    'email_confidence' => 'pattern',
    'phone' => '(218) 555-0137',
    'fit_score' => 0,
    'evidence' => 'Met at the SDBA conference',
]);
Leads::setStatus($id, 'meeting', 'Booked for Tuesday', $darren);
Leads::logAction($id, 'called', null, $darren);

$form = static fn (): array => LeadForm::fromLead(Leads::find($id));
$history = static fn (): int => (int) Database::scalar(
    "SELECT COUNT(*) FROM activities WHERE lead_id = :id AND body LIKE 'Edited%'", ['id' => $id]
);

// ------------------------------------------------- opening it changes nothing

$values = $form();
$check($values['vertical'] === 'Tribal gaming',
    'the form shows the words the vertical arrived as, not just the heading');
$check($values['fit_score'] === '', 'an unscored lead shows a blank score, not a 0');
$check($values['evidence'] === 'Met at the SDBA conference', 'and its evidence as text');

$before = Leads::find($id);
$result = Leads::edit($id, $values, $darren);
$check($result['ok'] && $result['changed'] === [], 'saving the form untouched changes nothing');
$check($history() === 0, 'and writes nothing to the history');
$check(Leads::find($id)['vertical'] === 'Casino' && Leads::find($id)['vertical_detail'] === 'Tribal gaming',
    'the vertical keeps its heading and its detail through the round trip');
$check((int) Leads::find($id)['fit_score'] === 0, 'and the score stays unscored');

// ------------------------------------------------- what Darren asked for

$values = $form();
$values['decision_maker'] = 'Darren Kuyper';
$result = Leads::edit($id, $values, $darren);
$after = Leads::find($id);

$check($result['ok'] && $result['changed'] === ['decision_maker'], 'a name can be changed, and only the name moves');
$check($after['decision_maker'] === 'Darren Kuyper', 'and is saved');

$note = (string) Database::scalar(
    "SELECT body FROM activities WHERE lead_id = :id AND body LIKE 'Edited%' ORDER BY id DESC LIMIT 1", ['id' => $id]
);
echo "       history: {$note}\n";
$check(str_contains($note, 'contact: darren → Darren Kuyper'), 'the history says what it was and what it is now');

// ------------------------------------------------- clearing on purpose

$values = $form();
$values['email'] = '';
$result = Leads::edit($id, $values, $darren);
$after = Leads::find($id);

$check($after['email'] === null, 'clearing a wrong email clears it — a blank here is deliberate');
$check($after['email_confidence'] === null, 'and its confidence with it, which described nothing any more');
$check($after['contact_key'] === 'darren kuyper',
    'the de-duplication key falls back to the name once there is no email');
$check(str_contains((string) Database::scalar(
    "SELECT body FROM activities WHERE lead_id = :id AND body LIKE 'Edited%' ORDER BY id DESC LIMIT 1", ['id' => $id]
), 'email cleared (was wrong@example.com)'), 'and the history keeps the address that was removed');

// ------------------------------------------------- renaming the company

$values = $form();
$values['company'] = 'Wapiti Ridge Casino & Lodge';
Leads::edit($id, $values, $darren);
$check(Leads::find($id)['company_key'] === Leads::companyKey('Wapiti Ridge Casino & Lodge'),
    'renaming the company moves its de-duplication key, or the next upload would make a second copy');

// ------------------------------------------------- what an edit cannot do

$check(Leads::find($id)['status'] === 'meeting', 'the disposition is not touched by an edit');
$check(Leads::find($id)['owner_note'] === 'Booked for Tuesday', 'nor the note on it');
$check(Leads::find($id)['last_action'] === 'called', 'nor the last action');

$values = $form();
$values['company'] = '';
$result = Leads::edit($id, $values, $darren);
$check(!$result['ok'] && isset($result['errors']['company']) && Leads::find($id)['company'] === 'Wapiti Ridge Casino & Lodge',
    'a lead cannot be saved without a company, and nothing is half-saved');

$values = $form();
$values['email'] = 'not an address';
$result = Leads::edit($id, $values, $darren);
$check(!$result['ok'] && isset($result['errors']['email']), 'nor with something that is not an email address');

// Turning this lead into a copy of another one for the same owner.
$other = Leads::create($darren, $runId, ['company' => 'Prairie Winds', 'decision_maker' => 'Rita Nakamura']);
$values = $form();
$values['company'] = 'Prairie Winds';
$values['decision_maker'] = 'Rita Nakamura';
$result = Leads::edit($id, $values, $darren);
$check(!$result['ok'] && str_contains($result['errors']['decision_maker'] ?? '', 'lead #' . $other),
    'an edit that would make two of the same person is refused, naming the other one');
$check(Leads::find($id)['company'] === 'Wapiti Ridge Casino & Lodge', 'and leaves this lead as it was');

// ------------------------------------------------- the screen, as Darren

$jar = tempnam(sys_get_temp_dir(), 'prospector-cookies-');

$http = static function (string $path, array $fields = [], ?string $cookies = null) use ($base, &$jar): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookies ?? $jar,
        CURLOPT_COOKIEFILE => $cookies ?? $jar,
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

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body];
};

$csrf = static function (string $html): string {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
};

$login = $http('/login')['body'];
$http('/login', ['csrf' => $csrf($login), 'email' => 'darren@44i.com', 'password' => '']);

$page = $http('/leads/' . $id)['body'];
$check(str_contains($page, '/leads/' . $id . '/edit'), 'the lead page has a way to edit it');

$edit = $http('/leads/' . $id . '/edit');
$check($edit['status'] === 200 && str_contains($edit['body'], 'value="Darren Kuyper"'),
    'the edit screen opens with the lead filled in');
$check(!str_contains($edit['body'], 'name="status"'), 'without a status field, which has its own control');

// Post the form as it stands with one change, the way a browser would.
preg_match_all('/<(?:input|textarea|select)[^>]*name="([a-z_]+)"[^>]*>/', $edit['body'], $names);
$posted = ['csrf' => $csrf($edit['body'])];
foreach (array_unique($names[1]) as $field) {
    $posted[$field] = $form()[$field] ?? '';
}
unset($posted['csrf']);
$posted = ['csrf' => $csrf($edit['body'])] + $posted;
$posted['decision_maker'] = 'Darren J. Kuyper';
$posted['title'] = 'Director of Marketing';

$saved = $http('/leads/' . $id . '/edit', $posted)['body'];
$check(Leads::find($id)['decision_maker'] === 'Darren J. Kuyper' && Leads::find($id)['title'] === 'Director of Marketing',
    'saving the screen saves the changes');
$check(str_contains($saved, '2 fields updated'), 'and says how many changed');
$check(str_contains($saved, 'Darren J. Kuyper'), 'landing back on the lead with the new name showing');

$list = $http('/leads?clear=1')['body'];
$check(str_contains($list, 'Darren J. Kuyper'), 'and on the list');

// ------------------------------------------------- not someone else's

$billyJar = tempnam(sys_get_temp_dir(), 'prospector-cookies-');
$login = $http('/login', [], $billyJar)['body'];
$http('/login', ['csrf' => $csrf($login), 'email' => 'billy@44idigital.com', 'password' => ''], $billyJar);

$denied = $http('/leads/' . $id . '/edit', [], $billyJar);
$check($denied['status'] === 403, "Billy cannot open the edit screen for Darren's lead");

$billyPage = $http('/leads', [], $billyJar)['body'];
$http('/leads/' . $id . '/edit', ['csrf' => $csrf($billyPage), 'company' => 'Hijacked'] + $posted, $billyJar);
$check(Leads::find($id)['company'] === 'Wapiti Ridge Casino & Lodge', 'nor save one by posting to it');

// --------------------------------------------------------------- cleaning up

Database::run('DELETE FROM leads');
Database::run('DELETE FROM runs WHERE id = :id', ['id' => $runId]);
@unlink($jar);
@unlink($billyJar);

echo "\n{$pass} passed, " . count($failures) . " failed\n";

if ($failures !== []) {
    echo "FAILED:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
