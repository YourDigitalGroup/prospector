/**
 * Leads-list filters that stay put until cleared.
 *
 *     php bin/serve.php &
 *     npm install --no-save playwright
 *     node tests/sticky_filters.js
 *
 * Driven in a browser rather than over curl because the way people actually
 * filter is by picking from a dropdown that submits itself — that is
 * JavaScript, and it has to carry the marker that makes a filter stick.
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
  + ' use Prospector\\Support\\Database; use Prospector\\Leads;'
  + ' use Prospector\\Runs; use Prospector\\Support\\Clock; use Prospector\\Users; '
  + code], { cwd: REPO }).toString().trim();

const n = Number(php('echo Database::scalar("SELECT COUNT(*) FROM leads");'));
if (n > 200) {
  console.error(`Refusing to run: ${n} leads on file looks like real data.`);
  process.exit(2);
}

// Two verticals, enough rows of each that a filter visibly narrows the list.
php(`
  Database::run("DELETE FROM leads");
  Database::run("UPDATE users SET lead_filters = NULL");
  $u = (int) Database::first("SELECT id FROM users WHERE loop = 'client'")["id"];
  $r = Runs::start($u, "client", Clock::today(), "upload", "Sticky test", "", "test");
  foreach (["Wapiti Ridge" => ["Tribal gaming", 90], "Prairie Winds" => ["Casino", 60],
            "Three Rivers" => ["Hospital", 85], "Cedar Valley" => ["Healthcare", 70]] as $c => [$v, $s]) {
    Leads::create($u, $r, ["company" => $c, "vertical" => $v, "fit_score" => $s]);
  }
`);

const savedFor = (email) => php(
  `echo (string) Database::scalar("SELECT lead_filters FROM users WHERE email = :e", ["e" => "${email}"]);`
);

(async () => {
  const launch = process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {};
  const browser = await chromium.launch(launch);

  const signIn = async (email, password) => {
    const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
    const page = await context.newPage();
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', email);
    if (password) await page.fill('input[name=password]', password);
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    return { context, page };
  };

  const companies = async (page) =>
    (await page.locator('.cell-primary a').allInnerTexts()).map((s) => s.trim()).sort();

  const query = (page) => new URL(page.url()).searchParams;

  const openLeads = async (page) => {
    // The way back to the list in real use: the sidebar link, which is a
    // plain /leads with nothing on it.
    await page.click('nav a[href$="/leads"]');
    await page.waitForLoadState('networkidle');
  };

  // ------------------------------------------------- picking a vertical

  let { context, page } = await signIn('scott@44interactive.com', '44i123');
  await page.goto(BASE + '/leads');
  await page.waitForLoadState('networkidle');

  check((await companies(page)).length === 4, 'the list starts unfiltered');

  // Picked the way a person picks it: the dropdown submits itself.
  await Promise.all([
    page.waitForNavigation(),
    page.selectOption('select[name=vertical]', 'Casino'),
  ]);
  await page.waitForLoadState('networkidle');

  check(query(page).get('vertical') === 'Casino', 'choosing a vertical filters the list');
  check(JSON.stringify(await companies(page)) === JSON.stringify(['Prairie Winds', 'Wapiti Ridge']),
    'to just the casinos');

  // ------------------------------------------------- leaving and coming back

  await page.goto(BASE + '/dashboard');
  await openLeads(page);

  check(query(page).get('vertical') === 'Casino', 'leaving the page and coming back keeps the vertical');
  check((await companies(page)).length === 2, 'and the list is still just the casinos');

  await page.reload();
  await page.waitForLoadState('networkidle');
  check(query(page).get('vertical') === 'Casino', 'a reload keeps it too');

  // The panel's own controls show what is applied, not just the list.
  check(await page.locator('select[name=vertical]').inputValue() === 'Casino',
    'and the dropdown still shows Casino selected');

  // ------------------------------------------------- several at once

  await page.fill('input[name=min_score]', '80').catch(() => {});
  const minScore = page.locator('[name=min_score]');
  if (await minScore.evaluate((el) => el.tagName) === 'SELECT') {
    const options = await minScore.locator('option').evaluateAll((os) => os.map((o) => o.value).filter(Boolean));
    await minScore.selectOption(options.find((v) => Number(v) >= 80) || options[options.length - 1]);
  }
  await Promise.all([page.waitForNavigation(), page.locator('#filters button[type=submit]').first().click()]);
  await page.waitForLoadState('networkidle');

  const chosenScore = query(page).get('min_score');
  check(query(page).get('vertical') === 'Casino' && !!chosenScore,
    `a second filter adds to the first (vertical=Casino, min_score=${chosenScore})`);

  await page.goto(BASE + '/dashboard');
  await openLeads(page);
  check(query(page).get('vertical') === 'Casino' && query(page).get('min_score') === chosenScore,
    'and both come back together');
  check(JSON.stringify(await companies(page)) === JSON.stringify(['Wapiti Ridge']),
    'still narrowing the list the same way');

  // ------------------------------------------------- per user, not per tab

  await context.close();
  ({ context, page } = await signIn('scott@44interactive.com', '44i123'));
  await openLeads(page);
  check(query(page).get('vertical') === 'Casino',
    'signing out and back in keeps them — they belong to the person, not the browser session');

  const other = await signIn('billy@44idigital.com', '');
  await other.page.goto(BASE + '/leads');
  await other.page.waitForLoadState('networkidle');
  check(!new URL(other.page.url()).searchParams.get('vertical'),
    "someone else's list is not filtered by Scott's choices");
  await other.context.close();

  // ------------------------------------------------- one-off looks don't stick

  // The unopened pill, a batch link, a dashboard tile: these open the list
  // pre-filtered for one look. If they replaced the saved filters, one click on
  // "6 unopened" would leave the list stuck on unopened from then on.
  const before = savedFor('scott@44interactive.com');

  await page.goto(BASE + '/leads?unopened=1');
  await page.waitForLoadState('networkidle');
  check(query(page).get('unopened') === '1' && !query(page).get('vertical'),
    'a link to the unopened leads shows exactly that');

  await page.goto(BASE + '/leads?status=new&sort=score');
  await page.waitForLoadState('networkidle');

  await page.fill('header input[name=q], .search input[name=q]', 'Wapiti');
  await Promise.all([page.waitForNavigation(), page.press('.search input[name=q]', 'Enter')]);
  await page.waitForLoadState('networkidle');
  check(query(page).get('q') === 'Wapiti', 'a search from the top bar searches');

  check(savedFor('scott@44interactive.com') === before,
    'and none of those replace the filters that were set in the panel');

  await openLeads(page);
  check(query(page).get('vertical') === 'Casino', 'so coming back to the list still finds them');

  // ------------------------------------------------- paging keeps them

  const nextPage = page.locator('a[href*="page=2"]').first();
  if (await nextPage.count()) {
    await Promise.all([page.waitForNavigation(), nextPage.click()]);
    check(query(page).get('vertical') === 'Casino', 'the next page is still filtered');
  } else {
    console.log('SKIP  paging — one page of results');
  }

  // ------------------------------------------------- clearing

  await page.goto(BASE + '/leads');
  await page.waitForLoadState('networkidle');
  await Promise.all([page.waitForNavigation(), page.locator('a:has-text("Clear filters")').first().click()]);
  await page.waitForLoadState('networkidle');

  check([...query(page).keys()].length === 0, 'Clear filters clears them');
  check((await companies(page)).length === 4, 'and the whole list is back');
  check(savedFor('scott@44interactive.com') === '', 'and nothing is remembered');

  await page.goto(BASE + '/dashboard');
  await openLeads(page);
  check([...query(page).keys()].length === 0, 'so coming back finds it unfiltered, as left');

  // Unpicking every filter by hand is the same as clearing them.
  await Promise.all([page.waitForNavigation(), page.selectOption('select[name=vertical]', 'Healthcare')]);
  await page.waitForLoadState('networkidle');
  await Promise.all([page.waitForNavigation(), page.selectOption('select[name=vertical]', '')]);
  await page.waitForLoadState('networkidle');
  check(savedFor('scott@44interactive.com') === '',
    'setting every filter back to "All" by hand also leaves nothing remembered');

  // ------------------------------------------------------------- cleanup

  await browser.close();
  php('Database::run("DELETE FROM leads"); Database::run("UPDATE users SET lead_filters = NULL");');

  console.log(`\n${pass} passed, ${failures.length} failed`);
  if (failures.length) {
    console.log('FAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
