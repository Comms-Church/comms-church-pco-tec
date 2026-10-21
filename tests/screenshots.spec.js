// Regenerates marketing/docs screenshots from the live (Playground) app.
// Run with: npm run screenshots  →  writes to screenshots/*.png
// Note: no PCO credentials in Playground — the settings screen and the TEC
// calendar populated with (seeded) events are what churches actually see.
const { test } = require('@playwright/test');

test.use({ viewport: { width: 1440, height: 900 } });

const shots = [
  ['/wp-admin/options-general.php?page=cctec-settings', 'settings.png'],
  ['/?post_type=tribe_events', 'events-calendar.png'],
];

// The smoke fixture's mocked syncs (including a deliberate 401) fill the sync
// log; keep that test output out of marketing captures.
test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ storageState: 'test-results/.auth/admin.json' });
  await page.goto('/wp-admin/options-general.php?page=cctec-settings');
  await Promise.all([
    page.waitForURL(/page=cctec-settings/),
    page.locator('form:has(input[name="cctec_action"][value="clear_log"]) button').click(),
  ]);
  await page.close();
});

for (const [path, file] of shots) {
  test(`capture ${file}`, async ({ page }) => {
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `screenshots/${file}`, fullPage: true });
  });
}
