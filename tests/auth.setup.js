// Logs into wp-admin once (Playground default credentials) and saves the
// session for the smoke + screenshot projects.
const { test: setup, expect } = require('@playwright/test');

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

setup('warm up The Events Calendar', async ({ request }) => {
  // TEC finishes registering REST routes / syncing its custom event tables
  // shortly after first boot — poll until the seeded events are visible so
  // the smoke tests start from a deterministic state.
  setup.setTimeout(90_000);
  for (let i = 0; i < 30; i++) {
    const res = await request.get('/wp-json/tribe/events/v1/events').catch(() => null);
    if (res && res.status() === 200) {
      const data = await res.json().catch(() => ({}));
      if ((data.events || []).length >= 2) return;
    }
    await new Promise((r) => setTimeout(r, 2000));
  }
  throw new Error('Seeded TEC events never appeared via REST — check tests/blueprint.json seeding.');
});
