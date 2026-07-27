const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error') errors.push(message.text());
  });
  const login = await page.request.post(`${base}/login`, {
    form: {
      email: process.env.VNV_TEST_EMAIL || 'info@vnvevents.com',
      password: process.env.VNV_TEST_PASSWORD || '12345'
    }
  });
  await login.body();
  await page.goto(`${base}/panel/growth-hub/video-studio`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-open-project]').first().click();
  await page.waitForTimeout(5000);
  const titleInput = page.locator('[data-overlay-text]').first();
  await titleInput.fill('VNV modern title');
  await page.locator('[data-add-overlay]').first().click();
  const result = {
    url: page.url(),
    projectTabs: await page.locator('[data-open-project]').count(),
    waveformCanvases: await page.locator('[data-waveform] canvas').count(),
    captionRows: await page.locator('[data-caption-list] .vs-caption-row').count(),
    konvaCanvases: await page.locator('[data-overlay-stage] canvas').count(),
    serializedOverlay: await page.locator('[name="overlay_layout_json"]').first().inputValue(),
    errors
  };
  fs.mkdirSync('test-results/video-editor', { recursive: true });
  fs.writeFileSync('test-results/video-editor/report.json', JSON.stringify(result, null, 2));
  await page.screenshot({ path: 'test-results/video-editor/workspace.png', fullPage: true });
  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  if (errors.length || !result.waveformCanvases || !result.captionRows || !result.konvaCanvases || !result.serializedOverlay) {
    process.exitCode = 1;
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
