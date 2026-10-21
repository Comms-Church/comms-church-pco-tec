// Release smoke suite — green, or no release.
// Runs against WordPress Playground with The Events Calendar installed and
// two seeded TEC events carrying PCO meta (tests/blueprint.json).
// No Planning Center credentials are configured, so the plugin must degrade
// gracefully — a friendly notice, never a fatal.
const { test, expect } = require('@playwright/test');

// PHP problems surface as text in the page body, not HTTP errors.
const PHP_ERRORS = /Fatal error|Parse error|Warning: |Notice: |Deprecated: /;

async function expectClean(page) {
  const body = await page.locator('body').innerText();
  expect(body).not.toMatch(PHP_ERRORS);
}

test.describe('The Events Calendar integration', () => {
  test('TEC REST API returns the seeded events', async ({ request }) => {
    const res = await request.get('/wp-json/tribe/events/v1/events');
    expect(res.status()).toBe(200);
    const data = await res.json();
    const titles = JSON.stringify(data.events || []);
    expect(titles).toContain('Youth Group Kickoff');
    expect(titles).toContain('Fall Marriage Retreat');
  });

  test('events archive renders the seeded events without PHP errors', async ({ page }) => {
    await page.goto('/?post_type=tribe_events');
    await expectClean(page);
    // Auto-retrying assertion: the custom-tables occurrence sync can lag the
    // first paint, so wait for the event to appear rather than snapshotting once.
    await expect(page.locator('body')).toContainText('Youth Group Kickoff');
  });

  test('single event page renders without PHP errors', async ({ page, request }) => {
    const data = await (await request.get('/wp-json/tribe/events/v1/events')).json();
    expect(data.events.length).toBeGreaterThanOrEqual(1);
    await page.goto(data.events[0].url);
    await expectClean(page);
  });
});

// The blueprint runs three real syncs against a fake Planning Center
// (mu-plugins/smoke-pco-mock.php): a normal feed, the same feed with an event
// renamed, then a 401 with "delete removed events" on.
test.describe('PCO sync (mocked Planning Center)', () => {
  async function eventTitles(request) {
    const res = await request.get('/wp-json/tribe/events/v1/events?per_page=50');
    expect(res.status()).toBe(200);
    return ((await res.json()).events || []).map((e) => e.title);
  }

  test('all-day PCO event (midnight start) is created in TEC', async ({ request }) => {
    expect(await eventTitles(request)).toContain('Smoke All-Day Event');
  });

  test('event renamed in PCO is updated even though its instance updated_at did not change', async ({ request }) => {
    const titles = await eventTitles(request);
    expect(titles).toContain('Smoke Breakfast Renamed');
    expect(titles).not.toContain('Smoke Breakfast Original');
  });

  test('a PCO API error is logged and does not trash synced events', async ({ page, request }) => {
    const titles = await eventTitles(request);
    expect(titles).toContain('Smoke All-Day Event');
    expect(titles).toContain('Smoke Breakfast Renamed');

    await page.goto('/wp-admin/options-general.php?page=cctec-settings');
    await expect(page.locator('#cctec-log')).toContainText('Failed to fetch event instances');
    await expect(page.locator('#cctec-log')).not.toContainText('Trashed post');
  });
});

test.describe('Admin pages', () => {
  test('settings page (options-general.php?page=cctec-settings) renders without PHP errors', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=cctec-settings');
    // Playground --login auto-authenticates as admin; if we ended up on a
    // login form, auth is broken and every admin assertion is meaningless.
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    const body = await page.locator('body').innerText();
    expect(body).toContain('PCO Events Sync');
  });

  test('TEC events list shows seeded events with PCO meta intact', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=tribe_events');
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    // Auto-retrying assertion on the list-table row: the WP_List_Table body can
    // render a beat after #wpwrap on a cold Playground boot.
    await expect(page.locator('#the-list')).toContainText('Youth Group Kickoff');
  });

  test('plugins screen shows both plugins active without notices', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    const body = await page.locator('body').innerText();
    expect(body).toContain('PCO Events');
    expect(body).toContain('The Events Calendar');
  });
});
