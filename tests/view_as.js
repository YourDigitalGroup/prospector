/**
 * "View as": an admin seeing Prospector exactly as Billy or Darren does.
 *
 *     php bin/serve.php &
 *     node tests/view_as.js
 *
 * Checks that the view really is theirs (their Home, their leads, no admin
 * screens), that it is read-only all the way down — forms, the Home queue's
 * buttons, the things that save quietly on a GET like opening a lead or
 * remembering filters — that the way back works, and that nobody but an admin
 * can get into it.
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
  + ' use Prospector\\Runs; use Prospector\\Support\\Clock; use Prospector\\Users; '
  + code], { cwd: REPO }).toString().trim();
const json = (code) => JSON.parse(php(code));

if (Number(php('echo Database::scalar("SELECT COUNT(*) FROM leads");')) > 200) {
  console.error('Refusing to run: this looks like real data.');
  process.exit(2);
}

const cleanup = () => php(`Database::run("DELETE FROM leads"); Database::run("DELETE FROM reminders"); Database::run("DELETE FROM activities");
  Database::run("DELETE FROM runs WHERE vertical = 'View-as test'");
  Database::run("UPDATE users SET active = 1, lead_filters = NULL");`);

cleanup();
const ids = json(`
  $d = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")["id"];
  $b = (int) Database::first("SELECT id FROM users WHERE loop = 'partner'")["id"];
  $s = (int) Database::first("SELECT id FROM users WHERE role = 'admin'")["id"];
  $rd = Runs::start($d, "client", Clock::today(), "upload", "View-as test", "", "test");
  $rb = Runs::start($b, "partner", Clock::today(), "upload", "View-as test", "", "test");
  $out = ["darren" => $d, "billy" => $b, "scott" => $s];
  $out["mine"] = Leads::create($d, $rd, ["company" => "Darren Only Casino", "decision_maker" => "Dana Whitfield", "phone" => "(605) 555-0101", "fit_score" => 80]);
  $out["billys"] = Leads::create($b, $rb, ["company" => "Billy Only Broadcasting", "decision_maker" => "Bo Reyes", "fit_score" => 70]);
  Users::saveLeadFilters($d, ["vertical" => "Casino"]);
  echo json_encode($out);
`);

const activities = () => Number(php('echo Database::scalar("SELECT COUNT(*) FROM activities");'));
const reminders = () => Number(php('echo Database::scalar("SELECT COUNT(*) FROM reminders");'));
const opened = (id) => php(`echo Database::scalar("SELECT opened_at FROM leads WHERE id = ${id}") ?? "";`);
const lastSeen = (id) => php(`echo Database::scalar("SELECT COALESCE(last_login_at, '') FROM users WHERE id = ${id}");`);
const darrensFilters = () => php(`echo json_encode(Users::leadFilters(${ids.darren}));`);

(async () => {
  const launch = process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {};
  const browser = await chromium.launch(launch);

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

  const bar = (page) => page.locator('.view-as-bar');
  const nav = (page, label) => page.locator('.nav-item', { hasText: label });

  // ------------------------------------------------- the way in

  const darrenSeenBefore = lastSeen(ids.darren);
  const { context, page, errors } = await signIn('scott@44interactive.com', '44i123');
  await page.goto(BASE + '/users');

  const viewButtons = page.locator('button[data-view-as]');
  check(await viewButtons.count() === 2, 'Users offers "View as" for Billy and Darren, not for Scott himself');
  check(await bar(page).count() === 0, 'no banner while Scott is himself');

  const darrenRow = page.locator('tr', { hasText: 'darren@44i.com' });
  await darrenRow.locator('button[data-view-as]').click();
  await page.waitForLoadState('networkidle');

  check(new URL(page.url()).pathname === '/dashboard', 'it lands on Home');
  check((await page.locator('h1').innerText()).includes('Darren'), 'Home greets Darren, not Scott');
  check(await bar(page).isVisible(), 'the "viewing as" bar is showing');
  check((await bar(page).innerText()).includes('Darren'), 'the bar names Darren');
  check((await bar(page).innerText()).includes('Back to Scott'), 'the bar offers the way back to Scott');
  check(await nav(page, 'Users').count() === 0 && await nav(page, 'Settings').count() === 0,
    'the admin screens are gone from the menu, as they are for Darren');
  check((await page.locator('.user-chip-name').innerText()).trim() === 'Darren', 'the account chip says Darren');

  // ------------------------------------------------- it really is his view

  const usersResponse = await page.goto(BASE + '/users');
  check(usersResponse.status() === 403, 'Users is closed to Darren, so it is closed here too');

  await page.goto(BASE + '/leads');
  check(page.url().includes('vertical=Casino'), "Darren's remembered filter comes back, as it would for him");
  await page.goto(BASE + '/leads?clear=1');
  const listText = await page.locator('.content').innerText();
  check(listText.includes('Darren Only Casino'), "Darren's own lead is listed");
  check(!listText.includes('Billy Only Broadcasting'), "Billy's lead is not — Darren cannot see it");

  // ------------------------------------------------- nothing saves

  check(JSON.parse(darrensFilters()).vertical === 'Casino', 'clearing the filters here left Darren\'s remembered ones alone');
  await page.goto(BASE + '/leads?f=1&status=won');
  check(JSON.parse(darrensFilters()).vertical === 'Casino' && !('status' in JSON.parse(darrensFilters())),
    'filtering here does not overwrite what Darren comes back to');

  await page.goto(BASE + `/leads/${ids.mine}`);
  check(opened(ids.mine) === '', 'opening his lead does not mark it opened — Darren still has not seen it');

  const before = activities();
  await page.click('button[name=log_action][value=called]');
  await page.waitForLoadState('networkidle');
  check(activities() === before, 'logging a call is refused');
  check((await page.locator('.alert-error').innerText()).includes('viewing as Darren'), 'and it says why');
  check(new URL(page.url()).pathname === `/leads/${ids.mine}`, 'and leaves Scott on the lead he was on');

  await page.goto(BASE + '/dashboard');
  await page.waitForTimeout(1500);
  const qButton = page.locator('.q-row button[name=log_action][value=called]').first();
  if (await qButton.count()) {
    await qButton.click();
    await page.waitForTimeout(900);
    check(activities() === before && reminders() === 0, "Home's quick buttons are refused too");
    check(await page.locator('.q-row').count() > 0, 'the row stays put, since nothing happened');
  } else {
    check(false, "Home's queue shows Darren's fresh lead");
  }

  // A hand-built POST, as if the button had been left on the page.
  const csrf = await page.locator('input[name=csrf]').first().inputValue();
  const forged = await page.request.post(BASE + `/leads/${ids.mine}/status`, {
    form: { csrf, status: 'won' },
    headers: { Accept: 'application/json' },
  });
  check(forged.status() === 403, 'a direct POST is turned away with 403');
  check(php(`echo Database::scalar("SELECT status FROM leads WHERE id = ${ids.mine}");`) !== 'won', 'and changed nothing');

  check(lastSeen(ids.darren) === darrenSeenBefore,
    "Darren's last-seen time is untouched — he has not signed in");

  // ------------------------------------------------- the way back

  await bar(page).locator('button').click();
  await page.waitForLoadState('networkidle');
  check(new URL(page.url()).pathname === '/users', 'Back returns Scott to Users');
  check(await bar(page).count() === 0, 'the bar is gone');
  check(await nav(page, 'Users').count() === 1, 'the admin screens are back');
  check((await page.locator('.alert-success').innerText()).includes('Darren'), 'and it confirms whose view he left');

  // ------------------------------------------------- endings that are not the button

  await page.locator('tr', { hasText: 'darren@44i.com' }).locator('button[data-view-as]').click();
  await page.waitForLoadState('networkidle');
  php(`Database::run("UPDATE users SET active = 0 WHERE id = ${ids.darren}");`);
  await page.goto(BASE + '/dashboard');
  check(await bar(page).count() === 0 && (await page.locator('.user-chip-name').innerText()).trim() === 'Scott',
    'if Darren is disabled mid-look, Scott is handed back his own view, still signed in');
  php(`Database::run("UPDATE users SET active = 1 WHERE id = ${ids.darren}");`);

  // ------------------------------------------------- on a phone

  await page.goto(BASE + '/users');
  await page.locator('tr', { hasText: 'billy@44idigital.com' }).locator('button[data-view-as]').click();
  await page.waitForLoadState('networkidle');
  await page.setViewportSize({ width: 375, height: 760 });
  await page.goto(BASE + '/leads');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check(overflow <= 0, 'the bar fits on a phone without pushing the page sideways' + (overflow > 0 ? ` (${overflow}px)` : ''));
  const box = await bar(page).boundingBox();
  check(box && box.x >= 15 && box.x + box.width <= 375 - 15, 'and keeps the 16px gutter');
  check((await bar(page).innerText()).includes('Billy'), 'viewing as Billy works the same way');
  await bar(page).locator('button').click();
  await page.waitForLoadState('networkidle');

  check(errors.length === 0, 'no script errors throughout' + (errors.length ? ' — ' + errors.join('; ') : ''));
  await context.close();

  // ------------------------------------------------- nobody else gets in

  const billy = await signIn('billy@44idigital.com');
  await billy.page.goto(BASE + '/leads/new');
  const billyToken = await billy.page.locator('input[name=csrf]').first().inputValue();
  await billy.page.request.post(BASE + '/users/view-as', { form: { csrf: billyToken, id: String(ids.darren) } });
  await billy.page.goto(BASE + '/dashboard');
  check((await billy.page.locator('h1').innerText()).includes('Billy') && await bar(billy.page).count() === 0,
    'Billy cannot view as Darren, even by posting the form himself');
  await billy.context.close();

  const scott = await signIn('scott@44interactive.com', '44i123');
  await scott.page.goto(BASE + '/users');
  const scottToken = await scott.page.locator('input[name=csrf]').first().inputValue();
  await scott.page.request.post(BASE + '/users/view-as', { form: { csrf: scottToken, id: String(ids.scott) } });
  await scott.page.goto(BASE + '/dashboard');
  check(await bar(scott.page).count() === 0, 'Scott cannot "view as" himself');

  // Admin taken away mid-look ends it: the person looking is signed out.
  await scott.page.goto(BASE + '/users');
  await scott.page.locator('tr', { hasText: 'darren@44i.com' }).locator('button[data-view-as]').click();
  await scott.page.waitForLoadState('networkidle');
  php(`Database::run("UPDATE users SET role = 'user' WHERE id = ${ids.scott}");`);
  await scott.page.goto(BASE + '/dashboard');
  php(`Database::run("UPDATE users SET role = 'admin' WHERE id = ${ids.scott}");`);
  check(new URL(scott.page.url()).pathname === '/login', 'if Scott stops being an admin mid-look, the look ends and he is signed out');
  await scott.context.close();

  await browser.close();
  cleanup();

  console.log(`\n${pass} passed, ${failures.length} failed`);
  if (failures.length) {
    console.log('FAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
  }
})().catch((error) => {
  php(`Database::run("UPDATE users SET role = 'admin' WHERE email = 'scott@44interactive.com'"); Database::run("UPDATE users SET active = 1");`);
  console.error(error);
  process.exit(1);
});
