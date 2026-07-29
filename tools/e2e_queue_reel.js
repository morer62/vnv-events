const { chromium } = require('playwright');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const project = process.env.VNV_REEL_PROJECT || '9';
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await (await page.request.post(`${base}/login`, { form: {
    email: process.env.VNV_TEST_EMAIL || 'info@vnvevents.com',
    password: process.env.VNV_TEST_PASSWORD || '12345'
  }})).body();
  await page.goto(`${base}/panel/growth-hub/video-studio?project=${project}`, { waitUntil: 'networkidle' });
  await page.locator('[data-open-dock=export]').click();
  const form = page.locator('form').filter({ has: page.locator('button:has-text("Render with captions")') });
  await form.locator('button').click();
  await page.waitForLoadState('networkidle');
  console.log(JSON.stringify({ project, url: page.url(), status: await page.locator('.ve-status').textContent(), errors }, null, 2));
  await browser.close();
  if (errors.length) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
