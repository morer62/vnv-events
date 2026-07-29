const { chromium } = require('playwright');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });
  page.setDefaultTimeout(8000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await (await page.request.post(`${base}/login`, { form: {
    email: process.env.VNV_TEST_EMAIL || 'info@vnvevents.com',
    password: process.env.VNV_TEST_PASSWORD || '12345'
  }})).body();
  await page.goto(`${base}/panel/growth-hub/video-studio?project=8`, { waitUntil: 'networkidle' });
  await page.route('**/panel/growth-hub/video-studio/timeline', route => route.abort());
  await page.locator('[data-side-tab=media]').click();
  const asset = page.locator('[data-media-name][data-media-url]').filter({ hasNot: page.locator('.ve-role:text-is("SOURCE")') }).first();
  const previewUrl = await asset.getAttribute('data-media-preview');
  const assetResponse = await page.request.get(new URL(previewUrl, base).toString(), { headers: { Range: 'bytes=0-1023' } });
  await asset.click();
  await page.locator('[data-chip-target]').selectOption('overlay-top-right');
  await page.locator('[data-chip-enter]').selectOption('slide-left');
  await page.locator('[data-chip-exit]').selectOption('fade');
  await page.locator('[data-save-command]').click();
  const firstBlock = page.locator('[data-transcript-block]').first();
  const start = Number(await firstBlock.getAttribute('data-start'));
  await page.locator('[data-preview]').evaluate((video, time) => { video.currentTime = time + .2; video.dispatchEvent(new Event('timeupdate')); }, start);
  await page.waitForTimeout(200);
  const pause = page.locator('[data-pause-start]').first();
  await pause.dispatchEvent('dblclick');
  const silenceOpen = await page.locator('.ve-silence-modal').evaluate(node => node.classList.contains('open'));
  await page.locator('[data-silence-action]').selectOption('reduce-1');
  await page.locator('[data-silence-save]').click();
  const result = {
    modalOpen: await page.locator('[data-command-modal]').evaluate(node => node.classList.contains('open')),
    instruction: await page.locator('[data-chip-instruction]').inputValue(),
    placement: await page.locator('[data-chip-target]').inputValue(),
    entrance: await page.locator('[data-chip-enter]').inputValue(),
    exit: await page.locator('[data-chip-exit]').inputValue(),
    autoButton: await page.getByText('Find mentions and create chips').count(),
    assetStatus: assetResponse.status(),
    chipCreated: await firstBlock.locator('.ve-command-chip').count(),
    chipOptions: await firstBlock.locator('.ve-command-chip').last().getAttribute('data-command-options'),
    previewVisible: await page.locator('[data-asset-preview]').evaluate(node => !node.hidden && node.children.length === 1),
    silenceOpen,
    silenceKeep: await pause.locator('[data-pause-keep]').inputValue(),
    positionButtons: await page.locator('[data-position-grid] button').count(),
    proxyControl: await page.locator('[data-proxy-form], [data-has-proxy="1"]').count(),
    errors
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  if (result.modalOpen || !result.instruction || result.assetStatus !== 206 || !result.chipCreated || !result.previewVisible || !result.silenceOpen || result.silenceKeep !== '1.00' || result.positionButtons !== 9 || !result.proxyControl || errors.length) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
