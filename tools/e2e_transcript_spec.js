const { chromium } = require('playwright');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });
  page.setDefaultTimeout(10000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  console.log('login');
  await (await page.request.post(`${base}/login`, { form: { email: 'info@vnvevents.com', password: '12345' } })).body();
  console.log('goto');
  await page.goto(`${base}/panel/growth-hub/video-studio?project=8`, { waitUntil: 'domcontentloaded' });
  await page.route('**/panel/growth-hub/video-studio/timeline', route => route.abort());
  const pause = page.locator('[data-pause-start]').first();
  console.log('pause');
  await pause.dblclick();
  const silenceOpen = await page.locator('.ve-silence-modal').evaluate(node => node.classList.contains('open'));
  const silenceSummary = await page.locator('[data-silence-summary]').textContent();
  await page.locator('[data-silence-action]').selectOption('reduce-1');
  await page.locator('[data-silence-save]').click();
  const silenceKeep = await pause.locator('[data-pause-keep]').inputValue();
  await page.getByRole('button', { name: '+ Text' }).click();
  console.log('text modal');
  await page.locator('[data-chip-instruction]').fill('Important AI insight');
  await page.locator('[data-position=top-center]').click();
  await page.locator('[data-chip-scale]').fill('120');
  await page.locator('[data-chip-opacity]').fill('85');
  await page.locator('[data-save-command]').click();
  console.log('text saved');
  const firstBlock = page.locator('[data-transcript-block]').first();
  const textChip = firstBlock.locator('.ve-command-chip[data-command-type=text]').last();
  const start = Number(await firstBlock.getAttribute('data-start'));
  await page.locator('[data-preview]').evaluate((video, time) => { video.currentTime = time + .1; video.dispatchEvent(new Event('timeupdate')); }, start);
  await page.waitForTimeout(100);
  const result = {
    silenceOpen, silenceSummary, silenceKeep,
    silenceChipClass: await pause.evaluate(node => node.classList.contains('ve-command-chip')),
    textChip: await textChip.count(),
    textOptions: await textChip.getAttribute('data-command-options'),
    textPreview: await page.locator('.ve-canvas > .ve-caption-preview').last().textContent(),
    positionButtons: await page.locator('[data-position-grid] button').count(),
    errors
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  if (!silenceOpen || silenceKeep !== '1.00' || !result.silenceChipClass || !result.textChip || !result.textOptions.includes('x: 50') || !result.textOptions.includes('y: 15') || !result.textPreview.includes('Important AI insight') || result.positionButtons !== 9 || errors.length) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
