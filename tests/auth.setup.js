// Logs into wp-admin once (Playground default credentials) and saves the
// session for the smoke + screenshot projects.
const { test: setup, expect } = require('@playwright/test');

// Events created by tests/blueprint.json.
const SEEDED_EVENTS = 2;

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/wp-admin/');

  // Playground's --login auto-login doesn't reach a fresh browser context, so
  // expect the login form; skip it only if a session somehow carried over.
  if (!(await page.locator('#wpwrap').isVisible())) {
    const user = page.getByLabel('Username or Email Address');
    const pass = page.locator('#user_pass');

    // wp-login.php runs wp_attempt_focus(): 200ms after the form renders it
    // focuses *and selects* #user_login. Typing before that fires let the timer
    // steal focus mid-fill, so the password landed in the username box. Wait
    // for WordPress to finish moving focus before touching the fields.
    await page.waitForFunction(
      () => document.activeElement && document.activeElement.id === 'user_login',
      null,
      { timeout: 10_000 }
    );
    await user.fill('admin');
    await pass.fill('password');
    await expect(user).toHaveValue('admin');
    await expect(pass).toHaveValue('password');

    await Promise.all([
      page.waitForURL(/\/wp-admin\//, { timeout: 30_000 }),
      page.locator('#wp-submit').click(),
    ]);
  }

  await expect(page.locator('#wpwrap')).toBeVisible();
  await page.context().storageState({ path: 'test-results/.auth/admin.json' });
});

async function waitFor(check, what) {
  for (let i = 0; i < 45; i++) {
    if (await check().catch(() => false)) return;
    await new Promise((r) => setTimeout(r, 2000));
  }
  throw new Error(`${what} never became ready — check tests/blueprint.json seeding.`);
}

setup('warm up The Events Calendar', async ({ browser, request }) => {
  // Playground reports "Ready" (and /wp-json/ answers) several seconds before
  // TEC has bootstrapped: during that window tribe_events isn't registered, so
  // the TEC REST routes 404 and edit.php?post_type=tribe_events dies with
  // "Invalid post type." Gate the smoke project on TEC actually serving the
  // seeded events, over REST and in the admin list table, so no test runs
  // inside that window.
  setup.setTimeout(240_000);

  await waitFor(async () => {
    const res = await request.get('/wp-json/tribe/events/v1/events');
    if (res.status() !== 200) return false;
    return ((await res.json()).events || []).length >= SEEDED_EVENTS;
  }, 'TEC REST events endpoint');

  const context = await browser.newContext({ storageState: 'test-results/.auth/admin.json' });
  const page = await context.newPage();
  await waitFor(async () => {
    await page.goto('/wp-admin/edit.php?post_type=tribe_events');
    return (await page.locator('#the-list a.row-title').count()) >= SEEDED_EVENTS;
  }, 'TEC admin events list');

  // Playground starts answering requests before the blueprint's last steps
  // finish. The mocked-sync fixture ends with a failing sync, then deletes its
  // fake credentials — wait for both, or tests (and screenshots) race it.
  await waitFor(async () => {
    await page.goto('/wp-admin/options-general.php?page=cctec-settings');
    const log = await page.locator('#cctec-log').innerText();
    return log.includes('Failed to fetch event instances')
      && (await page.locator('#cctec_app_id').inputValue()) === '';
  }, 'Mocked PCO sync fixture');
  await context.close();
});
