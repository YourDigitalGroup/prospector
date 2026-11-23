/**
 * Home, the sales dashboard, worked the way a seller works it.
 *
 *     php bin/serve.php &
 *     npm install --no-save playwright
 *     node tests/home.js
 *
 * tests/dashboard.php checks what the page is built from. This checks the page
 * itself: that the numbers land, that the queue's buttons do their job without
 * leaving the page, that the follow-up it offers is the one that gets set, and
 * that it holds together on a phone and for someone who has turned motion off.
 *
 * Settings, all optional:
 *   PROSPECTOR_TEST_URL     the dev server             (http://127.0.0.1:8099)
 *   PLAYWRIGHT_CHROMIUM     a Chromium binary to use   (Playwright's own)
 *
 * Local only. It signs in with the seeded dev accounts and writes leads.
 */

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = (process.env.PROSPECTOR_TEST_URL || 'http://127.0.0.1:8099').replace(/\/$/, '');
const REPO = path.resolve(__dirname, '..');

let pass = 0;
const failures = [];
const check = (ok, label) => {
  if (ok) { pass++; console.log(`PASS ${label}`); return; }
  failures.push(label);
  console.log(`FAIL ${label}`);
};

const php = (code) => execFileSync('php', ['-r',
  'require "app/bootstrap.php";'
  + ' use Prospector\\Support\\Database; use Prospector\\Leads; use Prospector\\Reminders;'
  + ' use Prospector\\Runs; use Prospector\\Support\\Clock; '
  + code], { cwd: REPO }).toString().trim();
const json = (code) => JSON.parse(php(code));

if (Number(php('echo Database::scalar("SELECT COUNT(*) FROM leads");')) > 200) {
  console.error('Refusing to run: this looks like real data.');
  process.exit(2);
}

// A queue with one of everything, owned by Darren.
const seed = () => json(`
  Database::run("DELETE FROM leads"); Database::run("DELETE FROM reminders"); Database::run("DELETE FROM activities");
  Database::run("DELETE FROM runs WHERE vertical = 'Home test'");
  $d = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")["id"];
  $r = Runs::start($d, "client", Clock::today(), "upload", "Home test", "", "test");
  $ago = fn ($s) => gmdate("Y-m-d H:i:s", strtotime("-" . $s));
  $mk = function ($co, $set = []) use ($d, $r) {
    $id = Leads::create($d, $r, ["company" => $co, "decision_maker" => "Dana Whitfield", "phone" => "(605) 555-0101", "fit_score" => 80]);
    if ($set) Database::update("leads", $set, ["id" => $id]);
    return $id;
  };
  $out = [];
  $out["due"] = $mk("Due Co");
  $out["dueReminder"] = Reminders::create($out["due"], $d, $ago("1 hour"), "Call back");
  $out["due2"] = $mk("Due Two Co");
  $out["due2Reminder"] = Reminders::create($out["due2"], $d, $ago("2 hours"));
  $out["due3"] = $mk("Due Three Co");
  $out["due3Reminder"] = Reminders::create($out["due3"], $d, $ago("3 hours"));
  $out["stalled"] = $mk("Stalled Co", ["last_action" => "called", "last_action_at" => $ago("3 days")]);
  $out["stalled2"] = $mk("Stalled Two Co", ["last_action" => "emailed", "last_action_at" => $ago("4 days")]);
  $out["fresh"] = $mk("Fresh Co");
  $out["darren"] = $d;
  echo json_encode($out);
`);

const reminders = (lead) => json(`echo json_encode(Database::all("SELECT * FROM reminders WHERE lead_id = ${lead} ORDER BY id"));`);
const callsThisWeek = () => Number(php('echo \\Prospector\\Dashboard::scoreboard((int) Database::first("SELECT id FROM users WHERE loop = \'client\'")["id"])["this"]["calls"];'));

(async () => {
  const launch = process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {};
  const browser = await browser_launch(launch);

  async function browser_launch(options) { return chromium.launch(options); }

  const signIn = async (email, password, options = {}) => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ...options });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', email);
    if (password) await page.fill('input[name=password]', password);
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    return { context, page, errors };
  };

  const row = (page, company) => page.locator('.q-row', { has: page.locator(`.q-company:text-is("${company}")`) });
  const gone = async (page, company) => {
    await page.waitForTimeout(700);
    return (await row(page, company).count()) === 0;
  };

  // ------------------------------------------------- arriving

  let ids = seed();
  let { context, page, errors } = await signIn('darren@44i.com');
  await page.goto(BASE + '/dashboard');
  await page.waitForTimeout(2200);

  check(errors.length === 0, 'Home loads without script errors' + (errors.length ? ' — ' + errors.join('; ') : ''));
  check((await page.locator('h1').innerText()).includes('Darren'), 'it greets Darren by name');

  const sections = await page.locator('[data-section]').evaluateAll((els) => els.map((e) => e.dataset.section));
  check(JSON.stringify(sections) === JSON.stringify(['due', 'stalled', 'fresh']), `the queue has the sections that have something in — ${sections.join(', ')}`);
  check((await page.locator('[data-section="due"] [data-section-count]').innerText()) === '3', 'three follow-ups due, counted');
  check((await page.locator('[data-todo-count]').innerText()) === '5', 'and the header says five leads need a follow-up');
  check((await page.locator('[data-score="calls"]').innerText()) === '0', 'the numbers count up to where they really are');

  // ------------------------------------------------- called, then the follow-up

  await row(page, 'Stalled Co').locator('button[value="called"]').click();
  const prompt = row(page, 'Stalled Co').locator('.q-prompt');
  await prompt.waitFor({ timeout: 5000 });

  const offered = (await prompt.innerText()).replace(/\s+/g, ' ');
  check(/Follow up .+, 9am\?/.test(offered), `logging a call offers the follow-up — "${offered.split('?')[0]}?"`);
  check((await page.locator('.dash-toast').innerText()).includes('Called — logged'), 'and says it was logged');

  await page.waitForTimeout(1300);
  check((await page.locator('[data-score="calls"]').innerText()) === '1', 'the Calls number moves without a reload');
  check((await page.locator('.ring-num').innerText()) === '1', 'and so does the ring');
  check(callsThisWeek() === 1, 'because the call really was recorded');

  await prompt.locator('[data-yes]').click();
  check(await gone(page, 'Stalled Co'), 'taking the follow-up clears the row');
  const set = reminders(ids.stalled);
  const expected = json(`echo json_encode(Reminders::followUpFor("called"));`);
  check(set.length === 1 && php(`echo Clock::display("${set[0] && set[0].due_at}", "Y-m-d g:ia");`) === `${expected.date} 9:00am`,
    'and sets it for exactly the day it offered, at 9');
  check((await page.locator('[data-section="stalled"] [data-section-count]').innerText()) === '1', 'the section count goes down');
  check((await page.locator('[data-todo-count]').innerText()) === '4', 'and so does the header');

  // Not now: logged, no follow-up.
  await row(page, 'Stalled Two Co').locator('button[value="emailed"]').click();
  await row(page, 'Stalled Two Co').locator('.q-prompt [data-no]').click();
  check(await gone(page, 'Stalled Two Co'), '"Not now" clears the row too');
  check(reminders(ids.stalled2).length === 0, 'without setting anything');
  check(await page.locator('[data-section="stalled"]').count() === 0, 'and an emptied section closes up');

  // ------------------------------------------------- due follow-ups

  await row(page, 'Due Co').locator('button[value="done"]').click();
  check(await gone(page, 'Due Co'), 'Done clears a due follow-up');
  check(reminders(ids.due)[0].done_at !== null, 'and marks it done');

  await row(page, 'Due Two Co').locator('button[value="snooze"]').click();
  check(await gone(page, 'Due Two Co'), 'Snooze clears it for now');
  const snoozed = reminders(ids.due2)[0];
  check(snoozed.done_at === null && new Date(snoozed.due_at + 'Z') > new Date(), 'and moves it forward rather than finishing it');

  // Calling a due follow-up is following up: the reminder completes itself.
  await row(page, 'Due Three Co').locator('button[value="called"]').click();
  await row(page, 'Due Three Co').locator('.q-prompt').waitFor({ timeout: 5000 });
  check(reminders(ids.due3)[0].done_at !== null, 'logging a call on a due follow-up completes it');
  await row(page, 'Due Three Co').locator('[data-no]').click();
  await page.waitForTimeout(700);

  await row(page, 'Fresh Co').locator('button[value="voicemail"]').click();
  await row(page, 'Fresh Co').locator('[data-no]').click();
  await page.waitForTimeout(700);
  check(!(await page.locator('[data-queue-empty]').isHidden()), 'working through everything shows All caught up');

  // A reload agrees with what the page showed.
  await page.reload();
  await page.waitForTimeout(2200);
  const left = await page.locator('.q-row .q-company').allInnerTexts();
  check(!(await page.locator('[data-queue-empty]').isHidden()), 'and a reload agrees — nothing comes back' + (left.length ? ' (still showing: ' + left.join(', ') + ')' : ''));
  check((await page.locator('[data-score="calls"]').innerText()) === '3', 'the week now shows the calls and voicemail logged here');

  // ------------------------------------------------- without JavaScript

  ids = seed();
  const nojs = await signIn('darren@44i.com', '', { javaScriptEnabled: false });
  await nojs.page.goto(BASE + '/dashboard');
  check((await nojs.page.locator('[data-score="calls"]').innerText()) === '0', 'without JavaScript the numbers are simply there');
  await nojs.page.locator('.q-row', { has: nojs.page.locator('.q-company:text-is("Due Co")') }).locator('button[value="done"]').click();
  await nojs.page.waitForLoadState('networkidle');
  check(nojs.page.url().endsWith('/dashboard') && reminders(ids.due)[0].done_at !== null,
    'and the buttons still work — they post and come back');
  check((await nojs.page.locator('.alert, .flash').first().innerText()).includes('Follow-up done'), 'with a message saying so');
  await nojs.context.close();

  // ------------------------------------------------- not someone else's

  const billy = await signIn('billy@44idigital.com', '');
  await billy.page.goto(BASE + '/dashboard');
  check(await billy.page.locator('.q-company:text-is("Due Two Co")').count() === 0, "Billy's Home has none of Darren's leads");
  // A real token of Billy's, so the request is refused for being Darren's lead
  // and not merely for lacking one. The New lead form always carries one.
  await billy.page.goto(BASE + '/leads/new');
  const token = await billy.page.locator('input[name=csrf]').first().inputValue();
  check(token.length > 10, 'Billy has a valid form token to try with');
  const forged = await billy.page.request.post(BASE + '/dashboard/act', {
    headers: { Accept: 'application/json' },
    form: { csrf: token, lead_id: String(ids.due2), op: 'done', reminder_id: String(ids.due2Reminder) },
  });
  check(forged.status() === 422 && reminders(ids.due2)[0].done_at === null, "and cannot work them by posting directly");
  await billy.context.close();

  // ------------------------------------------------- the admin view

  const scott = await signIn('scott@44interactive.com', '44i123');
  await scott.page.goto(BASE + '/dashboard');
  const teamRows = await scott.page.locator('.q-row').count();
  check(await scott.page.locator('.q-owner').count() > 0, 'the team view labels each lead with its owner' + ` (rows ${teamRows}, url ${scott.page.url().replace(BASE, '')})`);
  const brokenLinks = await scott.page.locator('a[href*="owner=Array"]').count();
  check(brokenLinks === 0, 'and its links carry no broken owner filter');
  await scott.page.selectOption('.dash-owner select', String(ids.darren));
  await scott.page.waitForLoadState('networkidle');
  check(scott.page.url().includes('owner=' + ids.darren) && await scott.page.locator('.q-owner').count() === 0,
    'and can be narrowed to one seller');
  await scott.context.close();

  // ------------------------------------------------- the lead page offers it too

  ids = seed();
  await page.goto(BASE + '/leads/' + ids.fresh);
  await Promise.all([page.waitForNavigation(), page.click('.action-row button[value="voicemail"]')]);
  check(page.url().includes('followup=voicemail'), 'logging on a lead comes back offering the follow-up');
  const leadPrompt = (await page.locator('.followup-prompt').innerText()).replace(/\s+/g, ' ');
  check(/Follow up .+, 9am\?/.test(leadPrompt), `on the lead page as well — "${leadPrompt.split('?')[0]}?"`);
  await Promise.all([page.waitForNavigation(), page.click('.followup-prompt button')]);
  check(reminders(ids.fresh).length === 1, 'and one tap sets it');

  await context.close();

  // ------------------------------------------------- phone, and less motion

  ids = seed();
  const phone = await signIn('darren@44i.com', '', { viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
  await phone.page.goto(BASE + '/dashboard');
  check(!(await phone.page.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'on a phone nothing runs off the side');
  check((await phone.page.locator('[data-score="calls"]').innerText()) === '0'
    && (await phone.page.locator('.q-row').first().evaluate((e) => getComputedStyle(e).animationName)) === 'none',
    'and with reduced motion asked for, nothing animates and every number is simply there');
  const buttonHeight = await phone.page.locator('.q-row .q-btn').first().evaluate((e) => e.getBoundingClientRect().height);
  check(buttonHeight >= 40, `the queue's buttons are thumb-sized (${Math.round(buttonHeight)}px)`);
  await phone.context.close();

  await browser.close();

  php(`Database::run("DELETE FROM leads"); Database::run("DELETE FROM reminders"); Database::run("DELETE FROM activities");
       Database::run("DELETE FROM runs WHERE vertical = 'Home test'");`);

  console.log(`\n${pass} passed, ${failures.length} failed`);
  if (failures.length) {
    console.log('FAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
