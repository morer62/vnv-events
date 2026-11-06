const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const base = process.env.GOURMET_QA_BASE || 'http://127.0.0.1:8765/index.php?url=';
const routes = [
  'pasta-station-catering-south-florida',
  'appetizer-catering-south-florida',
  'crepes-catering-south-florida',
  'taco-station-catering-south-florida',
  'corporate-catering-south-florida',
];

(async () => {
  const browser = await chromium.launch({ headless: true });
  const desktop = await browser.newPage({ viewport: { width: 1440, height: 1000 } });

  for (const route of routes) {
    const response = await desktop.goto(base + route, { waitUntil: 'networkidle' });
    assert.equal(response.status(), 200, `${route} did not return 200`);
    assert.equal(await desktop.locator('h1').count(), 1, `${route} must have one H1`);
    assert(await desktop.locator('form[data-cl-form]').isVisible(), `${route} short form is not visible`);
    assert(await desktop.locator('.cl-mobile-bar').count(), `${route} mobile bar is missing`);
    assert.equal(
      await desktop.locator('link[rel="canonical"]').getAttribute('href'),
      `https://vnvevents.com/${route}/`,
      `${route} canonical mismatch`,
    );
    assert.equal(
      await desktop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
      true,
      `${route} has horizontal overflow`,
    );
    const schema = JSON.parse(await desktop.locator('script[type="application/ld+json"]').first().textContent());
    assert.equal(schema['@graph'][0].offers.priceCurrency, 'USD', `${route} schema currency mismatch`);
    assert.equal(schema['@graph'][0].offers.eligibleQuantity.unitText, 'guest', `${route} schema unit mismatch`);
  }

  const mobile = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
  const appetizerUrl = base + 'appetizer-catering-south-florida&gclid=QA-GOOGLE-CLICK';
  const mobileResponse = await mobile.goto(appetizerUrl, { waitUntil: 'networkidle' });
  assert.equal(mobileResponse.status(), 200, 'Mobile Appetizer landing did not return 200');
  assert.equal(await mobile.locator('.vnvx-header').count(), 0, 'Ads mode must hide global navigation');
  assert(await mobile.locator('.cl-mobile-bar').isVisible(), 'Mobile Call/WhatsApp bar is not visible');
  assert(await mobile.locator('a[data-cl-call]').first().isVisible(), 'Call CTA is not visible');
  assert(await mobile.locator('a[data-cl-whatsapp]').first().isVisible(), 'WhatsApp CTA is not visible');
  const quoteButton = mobile.locator('a[href="#quote"]').first();
  assert(await quoteButton.isVisible(), 'Get a quote CTA is not visible');
  const quoteBox = await quoteButton.boundingBox();
  assert(quoteBox && quoteBox.y + quoteBox.height <= 844, 'Primary mobile CTA falls below the initial viewport');
  assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'Mobile landing overflows horizontally');
  await mobile.screenshot({ path: '.codex-tmp/gourmet-appetizer-mobile.png', fullPage: true });

  await browser.close();
  console.log(`Gourmet browser QA OK: ${routes.length} desktop routes + mobile Ads mode`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
