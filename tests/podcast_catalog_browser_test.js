const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const base = process.env.VNV_QA_BASE || 'http://localhost/vnv-events/index.php?url=';
const expectedIds = ['tLEGR2qboDU', '6GU7zrO8KQM', '-_yJ2EcitEA', 'piU0RpOPbN0', '_Yk0_-Zwn9k'];

function url(route) {
  const result = new URL(base);
  result.searchParams.set('url', route);
  return result.toString();
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  let checks = 0;

  for (const viewport of [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    await page.route('https://www.youtube-nocookie.com/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>YouTube embed QA</title>' }));
    const response = await page.goto(url('podcasts'), { waitUntil: 'domcontentloaded' });
    const label = `podcasts/${viewport.name}`;
    assert.equal(response.status(), 200, `${label}: status`);
    assert.equal(await page.locator('h1').count(), 1, `${label}: one H1`);
    assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'), 'https://vnvevents.com/podcasts/', `${label}: canonical`);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${label}: horizontal overflow`);
    assert.deepEqual(await page.locator('.podcasts-card [data-podcast-open].podcasts-card__media').evaluateAll((nodes) => nodes.map((node) => node.dataset.videoId)), expectedIds, `${label}: verified episode order`);
    assert.equal(await page.locator('.podcasts-card').count(), 5, `${label}: episode count`);
    assert.equal(await page.locator('#local-tech-lab .podcasts-card').count(), 2, `${label}: Local Tech Lab count`);
    assert.equal(await page.locator('#the-ground-floor .podcasts-card').count(), 3, `${label}: Ground Floor count`);
    const schemas = await page.locator('script[type="application/ld+json"]').allTextContents();
    assert.equal(schemas.length > 0, true, `${label}: structured data missing`);
    schemas.forEach((schema) => JSON.parse(schema));
    for (const image of await page.locator('.podcasts-card img').all()) {
      await image.scrollIntoViewIfNeeded();
      await image.evaluate((node) => node.complete || new Promise((resolve) => {
        node.addEventListener('load', resolve, { once: true });
        node.addEventListener('error', resolve, { once: true });
      }));
      assert.equal(await image.evaluate((node) => node.complete && node.naturalWidth > 0), true, `${label}: thumbnail did not load`);
    }

    const modal = page.locator('#podcastEpisodeModal');
    for (const id of expectedIds) {
      const trigger = page.locator(`.podcasts-card__media[data-video-id="${id}"]`);
      await trigger.scrollIntoViewIfNeeded();
      await trigger.click();
      assert.equal(await modal.getAttribute('aria-hidden'), 'false', `${label}/${id}: modal did not open`);
      assert.match(await modal.locator('iframe').getAttribute('src'), new RegExp(`/embed/${id.replace(/[-_]/g, '\\$&')}\\?`), `${label}/${id}: incorrect embed`);
      assert.equal(await modal.locator('[data-podcast-title]').textContent().then((value) => value.trim().length > 0), true, `${label}/${id}: modal title`);
      await page.keyboard.press('Escape');
      assert.equal(await modal.getAttribute('aria-hidden'), 'true', `${label}/${id}: Escape did not close`);
      assert.equal(await modal.locator('iframe').getAttribute('src'), 'about:blank', `${label}/${id}: player did not stop`);
      assert.equal(await trigger.evaluate((node) => document.activeElement === node), true, `${label}/${id}: focus was not restored`);
    }

    const request = page.locator('#local-tech-lab [data-vnv-open-quick-request]');
    await request.click();
    assert.equal(await page.locator('#vnvQuickRequestModal').getAttribute('aria-hidden'), 'false', `${label}: participation form did not open`);
    assert.equal(await page.locator('[data-vnv-quick-source]').inputValue(), 'podcasts_local_tech_lab', `${label}: participation source`);
    await page.keyboard.press('Escape');
    const headerRequest = page.locator('.vnvx-header [data-vnv-open-request]:visible').first();
    await headerRequest.click();
    assert.equal(await page.locator('#vnvRequestModal').getAttribute('aria-hidden'), 'false', `${label}: global New Request did not open`);
    await page.keyboard.press('Escape');
    assert.equal(pageErrors.length, 0, `${label}: page errors: ${pageErrors.join('; ')}`);
    checks++;
    await context.close();
  }

  const homeContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const home = await homeContext.newPage();
  const homeResponse = await home.goto(url(''), { waitUntil: 'domcontentloaded' });
  assert.equal(homeResponse.status(), 200, 'homepage status');
  assert.equal(await home.locator('.vnv-podcast-program').count(), 2, 'homepage program count');
  assert.equal(await home.locator('a[href*="podcasts"]').filter({ hasText: /Explore all podcast episodes/i }).count(), 1, 'homepage catalog link');
  assert.equal(await home.locator('iframe[title="VNV Sessions Playlist"]').count(), 1, 'DJ Sessions playlist changed');
  assert.equal(await home.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'homepage mobile overflow');
  await homeContext.close();

  await browser.close();
  console.log(`Podcast catalog browser QA OK: ${checks} catalog viewports + homepage`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
