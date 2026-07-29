const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const sourceProject = process.env.VNV_REEL_SOURCE || '8';
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  const login = await page.request.post(`${base}/login`, { form: {
    email: process.env.VNV_TEST_EMAIL || 'info@vnvevents.com',
    password: process.env.VNV_TEST_PASSWORD || '12345'
  }});
  if (!login.ok()) throw new Error(`Login failed: ${login.status()}`);
  await page.goto(`${base}/panel/growth-hub/distribution?mode=short-video&project=${sourceProject}`, { waitUntil: 'networkidle' });
  const sourceSelected = await page.locator('[data-reel-project]').inputValue();
  await page.locator('[name=reel_instructions]').fill('Open with the strongest complete business insight. Preserve complete phrases and natural breathing room. Use the approved long-video caption style and real word timing. Do not guess the active speaker or alternate framing.');
  await page.locator('button:has-text("Create editable reel")').click();
  await page.waitForLoadState('networkidle');
  const match = page.url().match(/[?&]project=(\d+)/);
  if (!match) throw new Error(`Reel project redirect missing: ${page.url()}`);
  const projectId = Number(match[1]);
  const result = {
    sourceSelected, projectId, url: page.url(),
    title: await page.locator('.ve-title h1').textContent(),
    type: await page.locator('.ve-title small').textContent(),
    transcriptBlocks: await page.locator('[data-transcript-block]').count(),
    chips: await page.locator('.ve-command-chip').count(),
    createReelButtons: await page.locator('a:has-text("Create reel")').count(),
    errors
  };
  fs.mkdirSync('test-results/reel-flow', { recursive: true });
  fs.writeFileSync('test-results/reel-flow/report.json', JSON.stringify(result, null, 2));
  await page.screenshot({ path: 'test-results/reel-flow/editor.png', fullPage: true });
  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  if (sourceSelected !== sourceProject || !result.transcriptBlocks || !result.type.includes('VERTICAL REEL') || errors.length) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
