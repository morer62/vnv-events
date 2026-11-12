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
      assert.equal(await page.locator('body').innerText().then((text) => /Ophyra/i.test(text)), false, `${label}: internal software brand is publicly visible`);

      if (route === '') {
        for (const status of ['Now Accepting', 'By Invitation', 'Coming Soon']) {
          assert.equal(await page.getByText(status, { exact: true }).count() > 0, true, `${label}: missing commercial status ${status}`);
        }
      }
      if (route === 'event-production') {
        assert.equal(await page.getByText(/From creative concepts to seamless execution\./).count(), 1, `${label}: final hero copy missing`);
        assert.equal(await page.locator('.process-step').count(), 5, `${label}: production method must contain five steps`);
        assert.equal(await page.getByText(/The VNV Ecosystem|The VNV 360 Way|Scope of Support|The Production Blueprint/).count(), 0, `${label}: redundant production block remains`);
        assert.equal(await page.getByText(/Support 20 organizations/).count(), 1, `${label}: 2027 roadmap goal missing`);
        const cases = page.locator('[data-vnv-story-component] .vnv-story-card');
        assert.equal(await cases.count(), 2, `${label}: production collaboration card count`);
        assert.equal((await cases.nth(0).innerText()).includes('Shake-A-Leg Miami'), true, `${label}: Shake-A-Leg must be first`);
        assert.equal((await cases.nth(1).innerText()).includes('Victoria Venezuela Foundation'), true, `${label}: Victoria must be second`);
        assert.equal(await page.locator('.vnv-story-roadmap').getByText('2027 Roadmap', { exact: true }).count(), 1, `${label}: roadmap must follow the collaboration cards`);
        assert.equal(await cases.nth(0).getByText('Where It Started', { exact: true }).count(), 1, `${label}: Shake-A-Leg label`);
        assert.equal(await cases.nth(1).getByText('Behind the Experience', { exact: true }).count(), 1, `${label}: Victoria label`);
        for (const image of await page.locator('.vnv-story-card img').all()) {
          assert.equal(await image.evaluate((node) => node.complete && node.naturalWidth > 0), true, `${label}: case-study image failed to load`);
        }
      }
      if (route === 'event-production' || route === 'nonprofit-partnerships') {
        const storyCards = page.locator('[data-vnv-story-component] .vnv-story-card');
        assert.equal(await storyCards.count(), 2, `${label}: shared collaboration cards`);
        const shakeTrigger = storyCards.nth(0).getByRole('button', { name: /View Our Story/ });
        await shakeTrigger.click();
        const shakeModal = page.locator('#vnv-story-modal-shake-a-leg');
        assert.equal(await shakeModal.isVisible(), true, `${label}: Shake-A-Leg modal did not open`);
        assert.equal(await shakeModal.getByText(/Our role: catering and event décor/).count(), 1, `${label}: verified Shake-A-Leg scope missing`);
        assert.equal(await shakeModal.getByText(/positive impact in the community/).count(), 1, `${label}: Shake-A-Leg community impact missing`);
        assert.equal(await shakeModal.locator('img').count(), 1, `${label}: Shake-A-Leg must use only its official logo`);
        await page.keyboard.press('Escape');
        assert.equal(await shakeModal.isHidden(), true, `${label}: Escape did not close Shake-A-Leg modal`);
        assert.equal(await shakeTrigger.evaluate((node) => document.activeElement === node), true, `${label}: story focus was not restored`);

        await storyCards.nth(1).getByRole('button', { name: /View Our Story/ }).click();
        const victoriaModal = page.locator('#vnv-story-modal-victoria-venezuela');
        assert.equal(await victoriaModal.isVisible(), true, `${label}: Victoria modal did not open`);
        assert.equal(await victoriaModal.locator('[data-vnv-gallery-thumb]').count(), 15, `${label}: Victoria verified gallery size`);
        assert.equal(await victoriaModal.getByText('View Full Event Gallery', { exact: false }).getAttribute('href'), 'https://www.victoriavenezuelafoundation.org/events/2026-golf-tournament', `${label}: official Victoria gallery link`);
        const firstGallerySrc = await victoriaModal.locator('[data-vnv-gallery-main]').getAttribute('src');
        await victoriaModal.locator('[data-vnv-gallery-next]').click();
        assert.notEqual(await victoriaModal.locator('[data-vnv-gallery-main]').getAttribute('src'), firstGallerySrc, `${label}: Victoria gallery did not advance`);
        await victoriaModal.click({ position: { x: 4, y: 4 } });
        assert.equal(await victoriaModal.isHidden(), true, `${label}: backdrop did not close Victoria modal`);
      }
      if (route === 'nonprofit-partnerships') {
        assert.equal(await page.getByText(/Now Accepting/).count() > 0, true, `${label}: nonprofit status missing`);
      }
      if (route === 'corporate-events') {
        const serviceCards = page.locator('.vnv-service-grid-card').filter({ has: page.locator('a', { hasText: 'REQUEST SERVICE' }) });
        assert.equal(await serviceCards.count(), 4, `${label}: four ordinary service cards expected`);
        for (const [service, destination] of [['Sound', 'vnv-live'], ['Multimedia', 'photo-booth-rental-video-photography-services-in-south-florida'], ['Staffing', 'event-staffing/']]) {
          const card = page.locator('.vnv-service-grid-card').filter({ has: page.getByRole('heading', { name: service, exact: true }) });
          assert.equal(await card.count(), 1, `${label}: ${service} card`);
          assert.match(await card.locator('a').getAttribute('href'), new RegExp(destination.replace('/', '\\/')), `${label}: ${service} ordinary service destination`);
          assert.equal(await card.locator('[data-vnv-open-quick-request]').count(), 0, `${label}: ${service} still opens invitation flow`);
        }
      }
      if (route === 'sponsor-activation-production' || route === 'corporate-events') {
        assert.equal(await page.getByText(/By Invitation/).count() > 0, true, `${label}: invitation status missing`);
        assert.equal(await page.getByText(/Planning for March 2027/).count(), 0, `${label}: retired March 2027 status remains`);
      }
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
