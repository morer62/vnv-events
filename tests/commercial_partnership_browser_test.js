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
  { name: 'tablet-1024', width: 1024, height: 768 },
  { name: 'desktop', width: 1440, height: 900 },
];
const quickRequestRoutes = new Set(['nonprofit-partnerships', 'sponsor-activation-production', 'event-production', 'corporate-events']);

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

      if (['nonprofit-partnerships', 'corporate-events', 'sponsor-activation-production'].includes(route)) {
        const offer = page.locator('.vnv-offer__spread');
        assert.equal(await offer.count(), 1, `${label}: production offer`);
        assert.equal(await offer.locator('.vnv-offer__deliverables li').count(), 3, `${label}: scope examples`);
        assert.equal(await page.locator('video source[src*="/video/productions/"]').count(), 0, `${label}: retired explainer`);
        assert.equal(await page.locator('.vnv-film video source[src*="/production-films/v4/"]').count(), 1, `${label}: current promo video`);
        assert.doesNotMatch(await offer.innerHTML(), /victoria[- ]venezuela/i, `${label}: case-study attribution in production offer`);
      }

      if (route === '') {
        for (const status of ['Now Accepting', 'By Invitation', 'Coming Soon']) {
          assert.equal(await page.getByText(status, { exact: true }).count() > 0, true, `${label}: missing commercial status ${status}`);
        }
        const planMyEvent = page.getByRole('button', { name: 'Plan My Event', exact: true });
        assert.equal(await planMyEvent.count(), 1, `${label}: Plan My Event hero button missing`);
        assert.equal(await planMyEvent.getAttribute('data-vnv-open-request'), '', `${label}: Plan My Event is not connected to New Request`);
        assert.equal(await page.locator('#vnvQuickRequestModal').count(), 0, `${label}: unused quick request modal remains mounted on home`);
        await planMyEvent.click();
        const requestModal = page.locator('#vnvRequestModal');
        assert.equal(await requestModal.getAttribute('aria-hidden'), 'false', `${label}: Plan My Event did not open the global New Request form`);
        assert.equal(await requestModal.locator('form').count(), 1, `${label}: New Request form is missing`);
        await page.keyboard.press('Escape');
        assert.equal(await requestModal.getAttribute('aria-hidden'), 'true', `${label}: Escape did not close New Request`);
        assert.equal(await planMyEvent.evaluate((node) => document.activeElement === node), true, `${label}: New Request focus was not restored to Plan My Event`);
      }
      if (route === 'event-production') {
        assert.equal(await page.getByRole('heading', { level: 1, name: 'Full event production. From brief to showtime.' }).count(), 1, `${label}: production hero missing`);
        assert.equal(await page.locator('.process-step').count(), 5, `${label}: production method must contain five steps`);
        assert.equal(await page.getByText(/The VNV Ecosystem|The VNV 360 Way|Scope of Support|The Production Blueprint/).count(), 0, `${label}: redundant production block remains`);
        assert.equal(await page.getByText('Support up to 12 nonprofit organizations', { exact: true }).count(), 1, `${label}: 2027 roadmap goal missing`);
        const cases = page.locator('[data-vnv-story-component] .vnv-story-card');
        assert.equal(await cases.count(), 2, `${label}: production collaboration card count`);
        assert.equal((await cases.nth(0).innerText()).includes('Shake-A-Leg Miami'), true, `${label}: Shake-A-Leg must be first`);
        assert.equal((await cases.nth(1).innerText()).includes('Victoria Venezuela Foundation'), true, `${label}: Victoria must be second`);
        assert.equal(await page.locator('.vnv-story-roadmap').getByText('Cause on Cue 2027', { exact: true }).count(), 1, `${label}: roadmap must follow the collaboration cards`);
        assert.equal(await cases.nth(0).getByText('Where It Started', { exact: true }).count(), 1, `${label}: Shake-A-Leg label`);
        assert.equal(await cases.nth(1).getByText('Behind the Experience', { exact: true }).count(), 1, `${label}: Victoria label`);
        for (const image of await page.locator('.vnv-story-card img').all()) {
          assert.equal(await image.evaluate((node) => node.complete && node.naturalWidth > 0), true, `${label}: case-study image failed to load`);
        }
        const productionTrigger = page.locator('[data-vnv-open-quick-request][data-quick-request-type="PRODUCTION"]').first();
        assert.equal(await productionTrigger.count(), 1, `${label}: specialized production request trigger missing`);
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
        assert.equal(await page.getByText('Now accepting applications', { exact: true }).count(), 1, `${label}: nonprofit status missing`);
        assert.equal(await page.getByRole('heading', { level: 1, name: 'Cause on Cue 2027' }).count(), 1, `${label}: campaign title`);
        assert.equal(await page.getByRole('link', { name: 'Apply to Cause on Cue 2027' }).getAttribute('href'), '#application', `${label}: application destination`);
        const journey = page.locator('#process .support-process__steps');
        assert.equal(await journey.locator('li').count(), 3, `${label}: expected three visible phases`);
        assert.equal(await journey.evaluate(node => getComputedStyle(node).listStyleType), 'none', `${label}: duplicate list numbering`);
        assert.match(await journey.innerText(), /Eligibility Review[\s\S]*Approved Support Plan[\s\S]*Impact & Results Review/, `${label}: review milestones missing`);
        const hero = page.locator('main .ve-hero');
        assert.match(await hero.locator('video').getAttribute('data-src'), /\/previews\/cause-on-cue\.mp4$/, `${label}: campaign preview`);
        assert.equal(await hero.locator('img[src*="victoria-venezuela"]').count(), 0, `${label}: Victoria photo should appear in the case study`);
        const platform = page.locator('#guest-platform');
        assert.equal(await platform.getByRole('heading', { name: 'One platform for the event and the year ahead' }).count(), 1, `${label}: nonprofit platform overview`);
        assert.match(await platform.innerText(), /donations[\s\S]*volunteers[\s\S]*sponsorship packages[\s\S]*no development cost/, `${label}: year-round nonprofit capabilities`);
        assert.doesNotMatch(await platform.innerHTML(), /victoria[- ]venezuela/i, `${label}: identifiable case-study portal in platform offer`);
        assert.equal(await page.locator('input[value="Donor & Sponsor Connections"]').count(), 1, `${label}: optional donor and sponsor support is missing`);
        assert.equal(await page.locator('#np-event').getAttribute('required'), null, `${label}: event name should be optional`);
        assert.equal(await page.getByText(/up to (60|80)%/).count(), 0, `${label}: unapproved discount remains`);
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
        const mobileAccount = page.locator('[data-vnvx-panel] .vnvx-mobile__account-link');
        assert.equal(await mobileAccount.isVisible(), true, `${label}: mobile Login / Client Access is not visible`);
        assert.match(await mobileAccount.getAttribute('href'), /login/, `${label}: mobile account access does not lead to login`);
        await page.locator('.vnvx-mobile-production summary').click();
        assert.equal(await page.locator('.vnvx-mobile-production__links a').count(), 5, `${label}: mobile production accordion`);
        assert.match(await page.locator('.vnvx-mobile-production__links a').filter({ hasText: 'Cause on Cue 2027' }).getAttribute('href'), /nonprofit-partnerships/, `${label}: mobile campaign link`);
        await page.locator('[data-vnvx-toggle]').first().click();
      } else {
        assert.equal(await page.locator('.vnvx-nav-group__menu a').count(), 5, `${label}: desktop production submenu`);
        assert.match(await page.locator('.vnvx-nav-group__menu a').filter({ hasText: 'Cause on Cue 2027' }).getAttribute('href'), /nonprofit-partnerships/, `${label}: desktop campaign link`);
        const desktopAccount = page.locator('.vnvx-header__login');
        assert.equal(await desktopAccount.isVisible(), true, `${label}: desktop Login / Access is not visible`);
        assert.match(await desktopAccount.getAttribute('href'), /login/, `${label}: desktop account access does not lead to login`);
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
        if (route === 'event-production') {
          assert.equal(await modal.locator('select[name="request_type"]').inputValue(), 'PRODUCTION', `${label}: production request type was not preserved`);
        }
        if (route === 'nonprofit-partnerships') {
          assert.equal(await modal.getByRole('heading', { name: 'Request an Exploratory Meeting' }).count(), 1, `${label}: exploratory meeting dialog`);
          assert.equal(await modal.locator('select[name="request_type"]').inputValue(), 'NONPROFIT', `${label}: meeting request type`);
        }
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
      if (route === 'vnv-events-productions/mommy-and-daddy-break') {
        assert.equal(await page.locator('#agenda .bc-agenda li').count(), 4, `${label}: sample morning agenda`);
        assert.match(await page.locator('.bc-cadence').innerText(), /two mornings a month.*different South Florida venue/i, `${label}: rotating locations`);
        assert.match(await page.locator('#agenda').innerText(), /massage table[\s\S]*On select dates[\s\S]*yoga/i, `${label}: optional wellness experiences`);
        assert.doesNotMatch(await page.locator('#agenda').innerText(), /\b\d+\s*min\b/i, `${label}: minute-by-minute schedule`);
      }
      if (route === 'vnv-events-productions/retro-glam') {
        const editions = page.locator('[data-retro-edition-open]');
        assert.equal(await editions.count(), 4, `${label}: four decade concepts`);
        assert.equal(await page.locator('#agenda').count(), 0, `${label}: retired party schedule`);
        const opener = editions.first();
        await opener.click();
        const dialog = page.locator('dialog[open]');
        assert.equal(await dialog.getByRole('heading', { name: 'Disco in Gold' }).count(), 1, `${label}: edition dialog`);
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        await page.waitForFunction(() => document.activeElement?.dataset.retroEditionOpen === 'disco-70s');
        assert.equal(await opener.evaluate(node => node === document.activeElement), true, `${label}: focus returns to the edition`);
      }
      checks++;
      await context.close();
    }
  }
  await browser.close();
  console.log(`Commercial partnership browser QA OK: ${checks} responsive pages`);
})().catch((error) => { console.error(error); process.exit(1); });
