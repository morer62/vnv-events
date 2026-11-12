const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const base = process.env.VNV_QA_BASE || 'http://localhost/vnv-events/index.php?url=';
const routes = [
  '',
  'nonprofit-partnerships',
  'sponsor-activation-production',
  'event-production',
  'corporate-events',
  'vnv-events-productions',
  'vnv-events-productions/retro-glam',
  'vnv-events-productions/mommy-and-daddy-break',
];
const viewports = [
  { name: 'mobile-360', width: 360, height: 800 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'mobile-430', width: 430, height: 932 },
  { name: 'desktop', width: 1440, height: 900 },
];
const quickRequestRoutes = new Set(['', 'nonprofit-partnerships', 'sponsor-activation-production', 'event-production', 'corporate-events']);

function url(route) {
  const result = new URL(base);
  result.searchParams.set('url', route);
  return result.toString();
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  let checks = 0;
  for (const viewport of viewports) {
    for (const route of routes) {
      const context = await browser.newContext({ viewport });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      const response = await page.goto(url(route), { waitUntil: 'domcontentloaded' });
      const label = `${route}/${viewport.name}`;
      assert.equal(response.status(), 200, `${label}: status`);
      assert.equal(await page.locator('h1').count(), 1, `${label}: must contain one H1`);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${label}: horizontal overflow`);
      assert.equal(errors.length, 0, `${label}: page errors: ${errors.join('; ')}`);
      const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
      assert.equal(canonical, route === '' ? 'https://vnvevents.com/' : `https://vnvevents.com/${route}/`, `${label}: canonical`);

      if (viewport.width < 860) {
        await page.locator('[data-vnvx-toggle]').first().click();
        assert.equal(await page.locator('[data-vnvx-panel]').evaluate((node) => node.classList.contains('is-open')), true, `${label}: mobile menu did not open`);
        await page.locator('.vnvx-mobile-production summary').click();
        assert.equal(await page.locator('.vnvx-mobile-production__links a').count(), 5, `${label}: mobile production accordion`);
        await page.locator('[data-vnvx-toggle]').first().click();
      } else {
        assert.equal(await page.locator('.vnvx-nav-group__menu a').count(), 5, `${label}: desktop production submenu`);
      }

      if (quickRequestRoutes.has(route)) {
        const trigger = page.locator('main [data-vnv-open-quick-request], section [data-vnv-open-quick-request]').first();
        assert.equal(await trigger.count(), 1, `${label}: quick request trigger missing`);
        await trigger.focus();
        await trigger.evaluate((node) => node.click());
        const modal = page.locator('#vnvQuickRequestModal');
        assert.equal(await modal.getAttribute('aria-hidden'), 'false', `${label}: quick request modal did not open`);
        assert.equal(await modal.locator('input[name="_token"]').inputValue().then(Boolean), true, `${label}: quick request CSRF token missing`);
        assert.equal(await modal.locator('textarea[name="project_brief"]').count(), 1, `${label}: quick brief missing`);
        assert.equal(await modal.locator('input[name="consent"]').count(), 1, `${label}: quick consent missing`);
        assert.equal(await page.locator('body').evaluate((node) => node.classList.contains('vnv-quick-request-lock')), true, `${label}: body lock missing`);
        if (await page.locator('.floating-whatsapp').count()) {
          assert.equal(await page.locator('.floating-whatsapp').evaluate((node) => getComputedStyle(node).pointerEvents), 'none', `${label}: WhatsApp overlaps modal`);
        }
        await page.keyboard.press('Escape');
        assert.equal(await modal.getAttribute('aria-hidden'), 'true', `${label}: Escape did not close quick request modal`);
        assert.equal(await trigger.evaluate((node) => document.activeElement === node), true, `${label}: focus was not restored to opener`);
      }

      if (['nonprofit-partnerships', 'sponsor-activation-production', 'vnv-events-productions'].includes(route)) {
        assert.equal(await page.locator('.floating-whatsapp').count(), 1, `${label}: floating WhatsApp missing`);
      }

      if (route === 'nonprofit-partnerships' || route === 'sponsor-activation-production' || route === 'vnv-events-productions') {
        const form = page.locator('form[action*="partnership-request"]');
        assert.equal(await form.count(), 1, `${label}: intake form missing`);
        assert(await form.locator('input[name="_token"]').inputValue(), `${label}: CSRF token missing`);
        assert.equal(await form.locator('input[name="consent"]').count(), 1, `${label}: consent missing`);
      }
      if (route.startsWith('vnv-events-productions/') && route !== 'vnv-events-productions') {
        const sponsorForm = page.locator('#vnvSponsorForm');
        assert.equal(await sponsorForm.count(), 1, `${label}: partnership-interest form missing`);
        assert.equal(await sponsorForm.locator('input[name="contact_consent"]').count(), 1, `${label}: sponsor consent missing`);
        assert.equal(await sponsorForm.getByText(/\$1,690|\$460|\$220|\$120/).count(), 0, `${label}: premature sponsor package price remains visible`);
      }
      checks++;
      await context.close();
    }
  }
  await browser.close();
  console.log(`Commercial partnership browser QA OK: ${checks} responsive pages`);
})().catch((error) => { console.error(error); process.exit(1); });
