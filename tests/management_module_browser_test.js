const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const base = (process.env.VNV_PANEL_QA_BASE || 'http://localhost/vnv-events').replace(/\/$/, '');
const email = process.env.VNV_PANEL_QA_EMAIL || 'info@vnvevents.com';
const password = process.env.VNV_PANEL_QA_PASSWORD || '12345';

(async () => {
  const browser = await chromium.launch({ headless: true });
  let checks = 0;

  for (const viewport of [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
  ]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await Promise.all([
      page.waitForURL(/\/panel\/home/),
      page.locator('form button[type="submit"]').click(),
    ]);

    assert.equal(await page.locator('.vnv-request-card__metadata').count() > 0, true, `${viewport.name}: formatted request metadata missing`);
    const requestTexts = await page.locator('#event-requests .vnv-request-card').allTextContents();
    assert.equal(requestTexts.some((text) => text.includes('{"ref_code"')), false, `${viewport.name}: raw JSON is visible`);

    await page.goto(`${base}/panel/planner-hub/management/users/create`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      document.querySelector('#mainFormCard').style.display = 'block';
      document.querySelector('#team-member-fields').style.display = 'block';
    });
    await page.locator('#mainFormCard button[data-bs-target="#managementModulesModal"]').click();
    await page.locator('#managementModulesModal .modal-dialog').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#managementModulesModal input[name="management_modules[]"]').count(), 7, `${viewport.name}: create-user module catalog count`);
    await page.keyboard.press('Escape');

    await page.goto(`${base}/panel/planner-hub/management/users`, { waitUntil: 'domcontentloaded' });
    const editHref = await page.locator('#members a[href*="management/users/edit"]').first().getAttribute('href');
    assert(editHref, `${viewport.name}: no Level 4 edit route found`);
    await page.goto(editHref, { waitUntil: 'domcontentloaded' });
    const moduleButton = page.locator('button[data-bs-target="#managementModulesModal"]');
    assert.equal(await moduleButton.count(), 1, `${viewport.name}: management module button missing`);
    await moduleButton.click();
    const modal = page.locator('#managementModulesModal .modal-dialog');
    await modal.waitFor({ state: 'visible' });
    assert.equal(await page.locator('#managementModulesModal input[name="management_modules[]"]').count(), 7, `${viewport.name}: module catalog count`);
    assert.equal(await page.getByText('Growth Hub', { exact: true }).count() > 0, true, `${viewport.name}: Growth Hub option missing`);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${viewport.name}: horizontal overflow`);
    const box = await modal.boundingBox();
    assert(box && box.width <= viewport.width + 1, `${viewport.name}: module modal exceeds viewport`);
    await page.screenshot({ path: `.codex-tmp/management-modules-${viewport.name}.png`, fullPage: false });
    assert.deepEqual(pageErrors, [], `${viewport.name}: page errors: ${pageErrors.join('; ')}`);

    checks++;
    await context.close();
  }

  await browser.close();
  console.log(`Management modules browser QA OK: ${checks} responsive views.`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
