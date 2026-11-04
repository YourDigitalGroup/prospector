/**
 * Follow-ups, the contacted switch, and Darren's vertical — as Darren.
 *
 *     php bin/serve.php &
 *     npm install --no-save playwright
 *     node tests/followups.js
 *
 * Signed in as the client-loop seller rather than the admin, because he is the
 * one who asked for all three and a non-admin goes down different paths: no
 * owner filter, only his own leads, only his own reminders.
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

const n = Number(php('echo Database::scalar("SELECT COUNT(*) FROM leads");'));
if (n > 200) {
  console.error(`Refusing to run: ${n} leads on file looks like real data.`);
  process.exit(2);
}

const ids = JSON.parse(php(`
  Database::run("DELETE FROM leads");
  Database::run("DELETE FROM reminders");
  Database::run("UPDATE users SET lead_filters = NULL");
  $u = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")["id"];
  $r = Runs::start($u, "client", Clock::today(), "upload", "Followups test", "", "test");
  $out = [];
  foreach (["Wapiti Ridge" => "Tribal gaming", "Prairie Winds" => "Casino",
            "Three Rivers" => "Hospital", "Cedar Valley" => "Healthcare"] as $c => $v) {
    $out[$c] = Leads::create($u, $r, ["company" => $c, "vertical" => $v, "decision_maker" => "Dana Whitfield"]);
  }
  // One already called, one emailed from inside Prospector.
  Leads::logAction($out["Prairie Winds"], "called", null, $u);
  Leads::touch($out["Cedar Valley"], "emailed");
  echo json_encode($out);
`));

const reminderRow = (lead) => JSON.parse(php(
  `echo json_encode(Database::first("SELECT * FROM reminders WHERE lead_id = ${lead} ORDER BY id DESC LIMIT 1"));`
));

(async () => {
  const launch = process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {};
  const browser = await chromium.launch(launch);
  const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
  const page = await context.newPage();

  // Darren signs in with his address alone.
  await page.goto(BASE + '/login');
  await page.fill('input[name=email]', 'darren@44i.com');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  const query = () => new URL(page.url()).searchParams;
  const companies = async () => (await page.locator('.cell-primary a').allInnerTexts()).map((s) => s.trim()).sort();
  const sidebarLeads = async () => {
    await page.click('nav a[href$="/leads"]');
    await page.waitForLoadState('networkidle');
  };
  const flash = async () => (await page.locator('.flash, .alert').first().innerText().catch(() => '')).trim();

  // ------------------------------------------- the vertical, as he reported it

  await page.goto(BASE + '/leads');
  await page.waitForLoadState('networkidle');
  await Promise.all([page.waitForNavigation(), page.selectOption('select[name=vertical]', 'Casino')]);
  await page.waitForLoadState('networkidle');
  check(JSON.stringify(await companies()) === JSON.stringify(['Prairie Winds', 'Wapiti Ridge']),
    'Darren picks Casino and sees the casinos');

  // "goes back to 'all' when I'm in a different one" — open a lead, come back.
  await page.click('.cell-primary a:has-text("Wapiti Ridge")');
  await page.waitForLoadState('networkidle');
  await page.click('a:has-text("Back to leads"), a.btn-ghost[href$="/leads"]');
  await page.waitForLoadState('networkidle');
  check(query().get('vertical') === 'Casino', 'opening a lead and going back keeps Casino');
  check(await page.locator('select[name=vertical]').inputValue() === 'Casino',
    'and the dropdown still says Casino, not All');

  await page.goto(BASE + '/dashboard');
  await sidebarLeads();
  check(query().get('vertical') === 'Casino', 'so does going to another page and back by the sidebar');

  await page.goto(BASE + '/leads?clear=1');
  await page.waitForLoadState('networkidle');

  // ------------------------------------------- show contacted

  const toggle = page.locator('a.btn-contacted');
  check(await toggle.count() === 1, 'there is a one-click Show contacted button');

  await Promise.all([page.waitForNavigation(), toggle.click()]);
  await page.waitForLoadState('networkidle');
  check(JSON.stringify(await companies()) === JSON.stringify(['Cedar Valley', 'Prairie Winds']),
    'it narrows to the lead he called and the one he emailed from Prospector');
  check(await toggle.getAttribute('aria-pressed') === 'true' && (await toggle.innerText()).includes('Contacted only'),
    'and stays lit while it is on, so the narrowed list is not mistaken for all of them');

  await page.goto(BASE + '/dashboard');
  await sidebarLeads();
  check(query().get('contacted') === '1' && (await companies()).length === 2,
    'it sticks like any other filter when he leaves and comes back');

  await Promise.all([page.waitForNavigation(), page.locator('a.btn-contacted').click()]);
  await page.waitForLoadState('networkidle');
  check((await companies()).length === 4 && !query().get('contacted'), 'pressing it again shows everyone');

  await page.goto(BASE + '/dashboard');
  await sidebarLeads();
  check(!query().get('contacted'), 'and that sticks too');

  // ------------------------------------------- setting a reminder

  await page.goto(BASE + '/leads/' + ids['Three Rivers']);
  await page.waitForLoadState('networkidle');

  check(await page.locator('#reminders h2:has-text("Remind me to follow up")').count() === 1,
    'the lead has a Remind me to follow up card');

  const presets = await page.locator('#reminders button[name=preset]').allInnerTexts();
  check(presets.length === 3 && presets[0].includes('Tomorrow'), `with one-tap choices — ${presets.join(' / ')}`);

  await page.fill('#remind_note', 'Ask about the ortho wing budget');
  await Promise.all([page.waitForNavigation(), page.locator('#reminders button[name=preset]').first().click()]);
  await page.waitForLoadState('networkidle');

  const set = reminderRow(ids['Three Rivers']);
  check(set && set.note === 'Ask about the ortho wing budget', 'Tomorrow sets one, note and all');
  check((await flash()).includes('Reminder set for'), `and says when — "${await flash()}"`);
  check(await page.locator('#reminders .reminder-list li').count() === 1, 'it is listed on the lead');
  check((await page.locator('#reminders .reminder-list li').innerText()).includes('Cancel'),
    'with a Cancel while it is still to come');

  // Any day and time.
  await page.fill('#remind_date', '2030-03-14');
  await page.fill('#remind_time', '14:30');
  await Promise.all([page.waitForNavigation(), page.click('#reminders button:has-text("Set reminder")')]);
  await page.waitForLoadState('networkidle');
  const custom = reminderRow(ids['Three Rivers']);
  check(custom.due_at === '2030-03-14 19:30:00', `a picked day and time is stored as typed in Central — ${custom.due_at} UTC`);

  // A time already gone is refused rather than firing instantly. Earlier
  // today, because the date field itself will not take a day before today —
  // which is right, but means a past day never reaches the server's own check.
  const todayLocal = php('echo Clock::today();');
  check(await page.locator('#remind_date').getAttribute('min') === todayLocal,
    'the date picker does not offer days already gone');
  await page.fill('#remind_date', todayLocal);
  await page.fill('#remind_time', '00:15');
  await Promise.all([page.waitForNavigation(), page.click('#reminders button:has-text("Set reminder")')]);
  await page.waitForLoadState('networkidle');
  check((await flash()).includes('already passed'), 'and earlier today is refused by the server with a reason');

  // On the list, under the contact.
  await page.goto(BASE + '/leads');
  await page.waitForLoadState('networkidle');
  const rowTag = page.locator('tr', { has: page.locator('.cell-primary a:has-text("Three Rivers")') }).locator('.tag-row .tag-reminder');
  check((await rowTag.innerText()).startsWith('Follow up '), `the list shows it under the contact — "${await rowTag.innerText()}"`);
  check(!(await rowTag.getAttribute('class')).includes('is-due'), 'outlined while it is still to come');

  // ------------------------------------------- when it comes due

  check(await page.locator('.pill-followup').count() === 0, 'nothing in the top bar until something is due');

  php(`Database::run("UPDATE reminders SET due_at = :d WHERE id = ${set.id}", ["d" => gmdate("Y-m-d H:i:s", strtotime("-5 minutes"))]);`);

  await page.reload();
  await page.waitForLoadState('networkidle');
  const pill = page.locator('.pill-followup');
  check((await pill.innerText().catch(() => '')).includes('1 follow-up due'), 'once due, the top bar says so');

  await Promise.all([page.waitForNavigation(), pill.click()]);
  await page.waitForLoadState('networkidle');
  check((await page.locator('h1').innerText()).includes('Follow-ups due'), 'and it opens a Follow-ups due list');
  check(JSON.stringify(await companies()) === JSON.stringify(['Three Rivers']), 'of just that lead');
  check((await page.locator('.tag-reminder.is-due').first().innerText()).includes('Follow up now'),
    'tagged Follow up now');

  // The due view is a one-off look — it must not become his saved filter.
  await page.goto(BASE + '/dashboard');
  await sidebarLeads();
  check(!query().get('due'), 'the due list does not stick to his Leads view');

  // Following up clears it.
  await page.goto(BASE + '/leads/' + ids['Three Rivers']);
  await page.waitForLoadState('networkidle');
  await Promise.all([page.waitForNavigation(), page.click('.action-row button[value=called]')]);
  await page.waitForLoadState('networkidle');
  check(await page.locator('.pill-followup').count() === 0, 'logging the call clears the top bar');
  check(reminderRow(ids['Three Rivers']).due_at === '2030-03-14 19:30:00'
    && JSON.parse(php(`echo json_encode(Database::first("SELECT done_at FROM reminders WHERE id = ${set.id}"));`)).done_at !== null,
    'the due one is done, and the one for March is still waiting');

  // Mark done by hand.
  const another = Number(php(`echo Reminders::create(${ids['Wapiti Ridge']}, (int) Database::first("SELECT id FROM users WHERE loop = 'client'")["id"], gmdate("Y-m-d H:i:s", strtotime("-1 minute")));`));
  await page.goto(BASE + '/leads/' + ids['Wapiti Ridge']);
  await page.waitForLoadState('networkidle');
  const item = page.locator('#reminders .reminder-list li');
  check((await item.innerText()).includes('Mark done'), 'a due one offers Mark done');
  await Promise.all([page.waitForNavigation(), item.locator('button').click()]);
  await page.waitForLoadState('networkidle');
  check(await page.locator('#reminders .reminder-list li').count() === 0 && (await flash()).includes('marked done'),
    'and pressing it clears it');
  check(another > 0, 'a due reminder can be created to test against');

  // ------------------------------------------- someone else's reminder

  // A reminder Darren did not set cannot be cleared by him through a forged form.
  const scottsReminder = Number(php(`echo Reminders::create(${ids['Cedar Valley']}, (int) Database::first("SELECT id FROM users WHERE role = 'admin'")["id"], gmdate("Y-m-d H:i:s", strtotime("+1 day")));`));
  const csrf = await page.locator('input[name=csrf]').first().inputValue();
  await page.request.post(`${BASE}/leads/${ids['Cedar Valley']}/reminder-done`, {
    form: { csrf, reminder_id: String(scottsReminder), return: '/leads' },
  });
  const after = JSON.parse(php(`echo json_encode(Database::first("SELECT done_at FROM reminders WHERE id = ${scottsReminder}"));`));
  check(after.done_at === null, "Darren cannot mark Scott's reminder done");

  // --------------------------------------------------------------- cleanup

  await browser.close();
  php('Database::run("DELETE FROM leads"); Database::run("DELETE FROM reminders"); Database::run("UPDATE users SET lead_filters = NULL");'
    // The batch record the seed leads hung off is left "running" otherwise, and
    // an open batch stops the next suite starting one for the same person.
    + ' Database::run("DELETE FROM runs WHERE vertical = \'Followups test\'");');

  console.log(`\n${pass} passed, ${failures.length} failed`);
  if (failures.length) {
    console.log('FAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
