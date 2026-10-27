/**
 * The bulk bar, driven in a real browser.
 *
 *     php bin/serve.php &
 *     npm install --no-save playwright
 *     node tests/bulk_bar.js
 *
 * Why this is in JavaScript when the rest of tests/ is PHP: the bug it guards
 * against lived entirely in assets/js/app.js. Every PHP check was green while
 * pressing Log, Apply or Archive raised "Delete for good?", and while Delete
 * itself quietly did nothing — the confirm dialog keyed off the form, which
 * every button shares, and re-submitted without the button that was pressed.
 * Only a browser pressing the buttons can see that.
 *
 * Settings, all optional:
 *   PROSPECTOR_TEST_URL     the dev server             (http://127.0.0.1:8099)
 *   PLAYWRIGHT_CHROMIUM     a Chromium binary to use   (Playwright's own)
 *
 * Local only. It signs in with the seeded dev admin account and deletes leads.
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

// The database is reached through the app's own bootstrap, so this test uses
// whatever config.local.php points at — the same database the server is using.
const php = (code) => execFileSync('php', ['-r',
  'require "app/bootstrap.php";'
  + ' use Prospector\\Support\\Database; use Prospector\\Leads;'
  + ' use Prospector\\Runs; use Prospector\\Support\\Clock; '
  + code], { cwd: REPO }).toString().trim();

const guard = () => {
  const n = Number(php('echo Database::scalar("SELECT COUNT(*) FROM leads");'));
  if (n > 200) {
    console.error(`Refusing to run: ${n} leads on file looks like real data.`);
    process.exit(2);
  }
};

// Three leads, so "one selected" and "all of them" are distinguishable.
const seed = () => php(`
  Database::run("DELETE FROM leads");
  $u = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")["id"];
  $r = Runs::start($u, "client", Clock::today(), "upload", "Bulk bar test", "", "test");
  foreach (["Alpha Co", "Beta Co", "Gamma Co"] as $c) Leads::create($u, $r, ["company" => $c]);
`);

const rows = () => JSON.parse(php(
  'echo json_encode(Database::all("SELECT company, status, last_action, archived_at FROM leads ORDER BY company"));'
));

(async () => {
  guard();

  const launch = process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {};
  const browser = await chromium.launch(launch);
  const page = await (await browser.newContext({ viewport: { width: 1500, height: 1000 } })).newPage();

  await page.goto(BASE + '/login');
  await page.fill('input[name=email]', 'scott@44interactive.com');
  await page.fill('input[name=password]', '44i123');
  await page.click('button[type=submit]');

  // Select exactly one row. The header has a select-all box too; ticking that
  // by mistake turns every "only the one I picked" check into a false pass.
  // Returns which company was ticked, read off the row rather than assumed —
  // the list sorts newest first, so "the first row" is not the first seeded.
  const pickOne = async () => {
    await page.goto(BASE + '/leads?archived=1');
    await page.waitForLoadState('networkidle');
    const box = page.locator('[data-check-row]').first();
    await box.check();
    return (await box.locator('xpath=ancestor::tr').locator('.cell-primary a').innerText()).trim();
  };

  const dialogIsOpen = () => page.locator('#bulk-delete-confirm').evaluate((d) => d.open);

  const settle = async () => {
    await page.waitForTimeout(300);
    await page.waitForLoadState('networkidle');
  };

  const touched = (list, test) => list.filter(test).length;

  // ------------------------------------------- buttons that must NOT ask

  const harmless = [
    ['Log', async () => {
      await page.selectOption('select[name=log_action]', 'called');
      await page.click('button[value=log]');
    }, (r) => r.last_action === 'called'],

    ['Apply a status', async () => {
      await page.selectOption('select[name=bulk_action]', 'contacted');
      await page.click('.bulk-group button:has-text("Apply")');
    }, (r) => r.status === 'contacted'],

    ['Archive', async () => {
      await page.click('button[value=archive]');
    }, (r) => r.archived_at !== null],
  ];

  for (const [name, press, applied] of harmless) {
    seed();
    await pickOne();
    await press();
    await page.waitForTimeout(300);

    check(!(await dialogIsOpen()), `${name} does not ask "Delete for good?"`);

    await settle();
    const after = rows();
    check(after.length === 3, `${name} deletes nothing`);
    check(touched(after, applied) === 1, `${name} reaches exactly the one lead selected`);
  }

  // ------------------------------------------------------- Delete must ask

  seed();
  await pickOne();
  await page.click('button[value=delete]');
  await page.waitForTimeout(300);

  check(await dialogIsOpen(), 'Delete asks first');
  check(rows().length === 3, 'and nothing is gone while it is asking');

  const said = (await page.locator('#bulk-delete-confirm [data-confirm-count]').innerText()).trim();
  check(said === '1 lead', `and says how many — "${said}"`);

  // Saying no must leave everything where it was.
  await page.click('#bulk-delete-confirm [data-confirm-no]:has-text("Keep")');
  await settle();
  check(!(await dialogIsOpen()), '"Keep them" closes the dialog');
  check(rows().length === 3, 'and keeps them');

  // Saying yes must actually delete. It used not to: the form was re-sent
  // without the Delete button, so the server never heard "delete" at all.
  const doomed = await pickOne();
  await page.click('button[value=delete]');
  await page.waitForTimeout(300);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('[data-confirm-go]')]);
  await settle();

  const left = rows();
  check(left.length === 2, '"Delete permanently" deletes');
  check(left.length === 2 && left.every((r) => r.company !== doomed),
    `exactly the one selected (${doomed}), and not the others`);

  // A second press after a confirmed delete must ask again rather than
  // remembering the earlier yes.
  await pickOne();
  await page.click('button[value=delete]');
  await page.waitForTimeout(300);
  check(await dialogIsOpen(), 'and the next Delete asks again');

  // -------------------------------------------------------------- cleanup

  await browser.close();
  php('Database::run("DELETE FROM leads"); Database::run("DELETE FROM runs WHERE geography = \'\' AND vertical = \'Bulk bar test\'");');

  console.log(`\n${pass} passed, ${failures.length} failed`);
  if (failures.length) {
    console.log('FAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
