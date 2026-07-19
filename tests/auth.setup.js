// Logs into wp-admin once (Playground default credentials) and saves the
// session for the smoke + screenshot projects.
const { test: setup, expect } = require('@playwright/test');

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/wp-login.php');
  // If Playground's boot-time auto-login already carried over, we're done.
  if (!(await page.locator('#user_login').isVisible().catch(() => false))) {
    await page.goto('/wp-admin/');
  } else {
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
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
