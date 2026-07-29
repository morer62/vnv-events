const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const project = process.env.VNV_REEL_PROJECT || '11';
  const expectedWidth = Number(process.env.VNV_EXPECT_WIDTH || 1080);
  const expectedHeight = Number(process.env.VNV_EXPECT_HEIGHT || 1920);
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
  const output = page.locator('[data-dock-pane=export] video');
  await output.waitFor({ state: 'visible' });
  const media = await output.evaluate(async video => {
    await new Promise((resolve, reject) => {
      if (video.readyState >= 1) return resolve();
      video.addEventListener('loadedmetadata', resolve, { once: true });
      video.addEventListener('error', reject, { once: true });
    });
    video.currentTime = Math.min(2, video.duration / 2);
    return { duration: video.duration, width: video.videoWidth, height: video.videoHeight, src: video.currentSrc };
  });
  const result = {
    project, status: await page.locator('.ve-status').textContent(),
    type: await page.locator('.ve-title small').textContent(),
    transcriptBlocks: await page.locator('[data-transcript-block]').count(),
    chips: await page.locator('.ve-command-chip').count(),
    outputLinks: await page.locator('a:has-text("Open edited master")').count(),
    media, errors
  };
  fs.writeFileSync('test-results/reel-flow/final-report.json', JSON.stringify(result, null, 2));
  await page.screenshot({ path: 'test-results/reel-flow/final-editor.png', fullPage: true });
  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  if (result.status.trim() !== 'COMPLETED' || media.width !== expectedWidth || media.height !== expectedHeight || errors.length) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
