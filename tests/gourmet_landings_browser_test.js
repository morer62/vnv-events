const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const base = process.env.GOURMET_QA_BASE || 'http://localhost:8765/index.php?url=';
const routes = [
  'pasta-station-catering-south-florida',
  'appetizer-catering-south-florida',
  'crepes-catering-south-florida',
  'taco-station-catering-south-florida',
  'corporate-catering-south-florida',
  'catering-stations-south-florida',
];
const modes = {
  organic: {},
  gclid: { gclid: 'QA-GOOGLE-CLICK' },
  utm: { utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'qa' },
  gbraid: { gbraid: 'QA-GBRAID' },
};
const viewports = [
  { name: 'mobile-360', width: 360, height: 740 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'desktop', width: 1440, height: 900 },
];

function pageUrl(route, params = {}) {
  const url = new URL(base);
  if (url.searchParams.has('url') || base.endsWith('url=')) {
    url.searchParams.set('url', route);
  } else {
    url.pathname = `${url.pathname.replace(/\/?$/, '/')}${route}/`;
  }
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
  return url.toString();
}

function easternTomorrow() {
  const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000);
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(tomorrow).reduce((out, part) => ({ ...out, [part.type]: part.value }), {});
  return `${parts.year}-${parts.month}-${parts.day}`;
}

async function assertNoBarOverlap(page, label) {
  const bar = page.locator('.cl-mobile-bar:visible,.cs-mobile-bar:visible').first();
  if (!(await bar.count())) return;
  const barBox = await bar.boundingBox();
  assert(barBox, `${label}: mobile bar has no bounding box`);
  const overlaps = await page.locator('a:visible,button:visible,input:visible,select:visible').evaluateAll((nodes, barTop) => nodes
    .filter((node) => !node.closest('.cl-mobile-bar,.cs-mobile-bar'))
    .map((node) => ({ text: (node.textContent || node.getAttribute('name') || '').trim(), box: node.getBoundingClientRect() }))
    .filter(({ box }) => box.top < window.innerHeight && box.bottom > 0 && box.bottom > barTop + 0.5)
    .map(({ text, box }) => ({ text, top: box.top, bottom: box.bottom })), barBox.y);
  assert.deepEqual(overlaps, [], `${label}: interactive controls overlap the fixed mobile bar: ${JSON.stringify(overlaps)}`);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  let pageChecks = 0;

  for (const viewport of viewports) {
    for (const [mode, params] of Object.entries(modes)) {
      for (const route of routes) {
        const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } });
        const page = await context.newPage();
        const response = await page.goto(pageUrl(route, params), { waitUntil: 'domcontentloaded' });
        const label = `${route} / ${mode} / ${viewport.name}`;
        assert.equal(response.status(), 200, `${label}: HTTP status`);
        assert.equal(await page.locator('h1').count(), 1, `${label}: must have one H1`);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${label}: horizontal overflow`);
        assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'), `https://vnvevents.com/${route}/`, `${label}: canonical mismatch`);

        const isAds = mode !== 'organic';
        assert.equal(await page.locator('.vnvx-header').count(), isAds ? 0 : 1, `${label}: navigation visibility mismatch`);
        const formSelector = route === 'catering-stations-south-florida' ? 'form[data-cs-form]' : 'form[data-cl-form]';
        assert.equal(await page.locator(formSelector).count(), route === 'catering-stations-south-florida' && !isAds ? 0 : 1, `${label}: short-form visibility mismatch`);

        if (!isAds) {
          const requestModal = page.locator('#vnvRequestModal');
          assert.equal(await requestModal.count(), 1, `${label}: New Request modal is missing`);
          await page.locator('[data-vnv-open-request]:visible').first().click();
          assert.equal(await requestModal.getAttribute('aria-hidden'), 'false', `${label}: New Request did not open the modal`);
          assert.equal(await requestModal.evaluate((modal) => modal.classList.contains('is-open')), true, `${label}: New Request modal is not visible`);
          await page.keyboard.press('Escape');
          assert.equal(await requestModal.getAttribute('aria-hidden'), 'true', `${label}: Escape did not close New Request modal`);
        }

        if (isAds) {
          const prohibited = await page.locator('a[href]').evaluateAll(
            (anchors, individual) => anchors
              .map((a) => a.getAttribute('href') || '')
              .filter((href) => /\/product\/|\/vnv-gourmet\/?(?:$|[?#])|store-categories|thepastastation\.net/i.test(href)
                || (individual && /catering-stations-south-florida/i.test(href))),
            route !== 'catering-stations-south-florida',
          );
          assert.deepEqual(prohibited, [], `${label}: Ads mode contains prohibited outbound links: ${prohibited.join(', ')}`);
          const min = await page.locator(`${formSelector} input[name="event_date"]`).getAttribute('min');
          assert.equal(min, easternTomorrow(), `${label}: minimum date is not tomorrow in Eastern Time`);
          const ref = await page.locator(`${formSelector} input[name="ref_code"]`).inputValue();
          assert(ref.startsWith('GA-'), `${label}: paid ref_code must start GA-`);
        }

        const schemaTexts = await page.locator('script[type="application/ld+json"]').allTextContents();
        for (const schemaText of schemaTexts) {
          JSON.parse(schemaText);
          assert(!schemaText.includes('AggregateRating'), `${label}: self-published AggregateRating remains`);
          assert(!schemaText.includes('"Review"'), `${label}: self-published Review remains`);
        }

        const expectedCall = await page.locator('a[href="tel:+13052045427"]').count();
        assert(expectedCall > 0, `${label}: official Call link is missing`);
        if (isAds) {
          const configuredWhatsapp = await page.locator('a[data-cl-whatsapp],a[data-cs-whatsapp]').first().getAttribute('href');
          assert(/^https:\/\/wa\.me\/\d+/.test(configuredWhatsapp), `${label}: configured WhatsApp link is missing`);
        }

        if (route === 'catering-stations-south-florida') {
          const cardKeys = await page.locator('[data-station-card]').evaluateAll((cards) => cards.slice(0, 4).map((card) => card.getAttribute('data-station-card')));
          assert.deepEqual(cardKeys, ['pasta', 'appetizer', 'crepes', 'taco'], `${label}: service-card priority order changed`);
        }
        if (route === 'corporate-catering-south-florida') {
          assert(await page.getByText('Corporate catering stations', { exact: true }).count(), `${label}: Corporate must lead with stations`);
          assert(await page.getByText('Pasta or Taco', { exact: true }).count(), `${label}: Corporate main-station choice is missing`);
          assert.equal(await page.locator('.cl-hero').getByText(/Meal boxes from/i).count(), 0, `${label}: meal boxes must remain outside the hero`);
        }

        if (viewport.width <= 760) await assertNoBarOverlap(page, label);
        pageChecks++;
        await context.close();
      }
    }
  }

  const waContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const waPage = await waContext.newPage();
  await waPage.goto(pageUrl('catering-stations-south-florida', { gclid: 'QA-DYNAMIC-WA', vnv_test: '1' }), { waitUntil: 'domcontentloaded' });
  await waPage.selectOption('#cs-service', 'appetizer');
  await waPage.fill('#cs-date', easternTomorrow());
  await waPage.selectOption('#cs-guests', '41-75');
  await waPage.fill('#cs-zip', '33351');
  await waPage.locator('[data-cs-whatsapp]').first().evaluate((anchor) => {
    anchor.addEventListener('click', (event) => event.preventDefault(), { once: true });
    anchor.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  });
  const dynamicHref = await waPage.locator('[data-cs-whatsapp]').first().getAttribute('href');
  const decodedWhatsapp = decodeURIComponent(dynamicHref);
  for (const needle of ['Appetizer', easternTomorrow(), '41', '33351', 'GA-STATIONS-']) {
    assert(decodedWhatsapp.includes(needle), `Dynamic WhatsApp message missing ${needle}`);
  }
  await waPage.screenshot({ path: '.codex-tmp/gourmet-comparison-mobile.png', fullPage: true });
  await waContext.close();

  const seedContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const seedPage = await seedContext.newPage();
  await seedPage.goto(pageUrl('catering-stations-south-florida', { gclid: 'QA-PERSISTENT' }), { waitUntil: 'domcontentloaded' });
  await seedPage.goto(pageUrl('pasta-station-catering-south-florida'), { waitUntil: 'domcontentloaded' });
  assert.equal(await seedPage.locator('.vnvx-header').count(), 0, 'Ads visual mode did not persist while navigating from comparison to Pasta in the same session');
  const persistentCookies = (await seedContext.cookies()).filter((cookie) => cookie.name === 'vnv_gclid');
  assert.equal(persistentCookies.length, 1, 'Persistent attribution cookie was not created');
  await seedContext.close();

  const freshContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await freshContext.addCookies(persistentCookies);
  const freshPage = await freshContext.newPage();
  await freshPage.goto(pageUrl('pasta-station-catering-south-florida'), { waitUntil: 'domcontentloaded' });
  assert.equal(await freshPage.locator('.vnvx-header').count(), 1, 'A 90-day attribution cookie must not reactivate Ads visual mode in a new session');
  assert.equal(await freshPage.locator('input[name="gclid"]').inputValue(), 'QA-PERSISTENT', 'Persistent attribution was not carried into the lead form');
  assert((await freshPage.locator('input[name="ref_code"]').inputValue()).startsWith('GA-'), 'Persistent paid attribution must retain a GA ref_code');
  await freshContext.close();

  const perfContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const perfPage = await perfContext.newPage();
  await perfPage.addInitScript(() => {
    window.__qaLcp = 0;
    window.__qaCls = 0;
    new PerformanceObserver((list) => { for (const entry of list.getEntries()) window.__qaLcp = Math.max(window.__qaLcp, entry.startTime); }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((list) => { for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__qaCls += entry.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  await perfPage.goto(pageUrl('appetizer-catering-south-florida', { gclid: 'QA-PERF' }), { waitUntil: 'load' });
  await perfPage.waitForTimeout(1200);
  const metrics = await perfPage.evaluate(() => ({ lcp: window.__qaLcp, cls: window.__qaCls }));
  assert(metrics.lcp > 0 && metrics.lcp <= 1500, `Mobile LCP must be at most 1.5s; measured ${metrics.lcp}ms`);
  assert(metrics.cls <= 0.05, `Mobile CLS must be at most 0.05; measured ${metrics.cls}`);
  await perfContext.close();

  const leadContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const leadPage = await leadContext.newPage();
  await leadPage.goto(pageUrl('appetizer-catering-south-florida', { gclid: 'QA-E2E-LEAD', vnv_test: '1' }), { waitUntil: 'domcontentloaded' });
  await leadPage.fill('#cl-date', easternTomorrow());
  await leadPage.selectOption('#cl-guests', '25-40');
  await leadPage.fill('#cl-zip', '33351');
  await leadPage.fill('#cl-name', 'QA Gourmet Ads Test');
  await leadPage.fill('#cl-email', 'qa.gourmet.ads@example.invalid');
  await Promise.all([
    leadPage.waitForURL(/lead=success/),
    leadPage.locator('form[data-cl-form] button[type="submit"]').click(),
  ]);
  assert.equal(await leadPage.locator('h1').count(), 1, 'Test lead redirect did not return to the landing');
  await leadContext.close();

  await browser.close();
  console.log(`Gourmet browser QA OK: ${pageChecks} matrix pages; dynamic WhatsApp; session attribution; test lead persisted; LCP ${metrics.lcp.toFixed(1)}ms; CLS ${metrics.cls.toFixed(4)}`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
