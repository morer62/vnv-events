const { webkit } = require('playwright');
const assert = require('node:assert/strict');

const base = process.env.VNV_QA_BASE || 'http://localhost/vnv-events/index.php?url=';
const routes = [
  '',
  'event-production',
  'nonprofit-partnerships',
  'corporate-events',
  'sponsor-activation-production',
  'vnv-events-productions',
];

function url(route) {
  const result = new URL(base);
  result.searchParams.set('url', route);
  return result.toString();
}

(async () => {
  const browser = await webkit.launch({ headless: true });
  for (const route of routes) {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const response = await page.goto(url(route), { waitUntil: 'domcontentloaded' });
    assert.equal(response.status(), 200, `${route}: status`);
    assert.equal(await page.locator('h1').count(), 1, `${route}: H1`);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${route}: horizontal overflow`);
    assert.deepEqual(errors, [], `${route}: JavaScript errors`);
    await page.close();
  }
  await browser.close();
  console.log(`WebKit mobile QA OK: ${routes.length} pages`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
