const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const site = process.env.VNV_QA_SITE || 'http://127.0.0.1:18846';
const roles = ['platform-administrator', 'organization-administrator', 'project-manager', 'team-member', 'external-participant'];

(async () => {
  const browser = await chromium.launch();
  try {
    for (const width of [390, 1366]) {
      const page = await browser.newPage({ viewport: { width, height: 920 } });
      for (const route of ['event-production', 'corporate-events', 'nonprofit-partnerships', 'sponsor-activation-production']) {
        await page.goto(site + '/' + route + '/', { waitUntil: 'domcontentloaded' });
        const entry = page.locator('main a[href*="/operational-software"]').first();
        assert.equal(await entry.isVisible(), true, route + ' software entry');
        await entry.click();
        await page.getByRole('heading', { name: 'Event production software', exact: true }).waitFor();
      }
      const chooser = page.getByRole('navigation', { name: 'Walkthrough by role' });
      for (const [i, role] of roles.entries()) {
        await chooser.locator('a').nth(i).click();
        assert.equal(new URL(page.url()).searchParams.get('role'), role);
        const player = page.locator('#ops-player');
        assert.match(await player.locator('source').getAttribute('src'), new RegExp('/' + role + '[.]mp4$'));
        assert.equal(await chooser.locator('[aria-current]').count(), 1);
        const jumps = page.locator('#ops-chapters button');
        await jumps.first().waitFor();
        assert.equal(await jumps.count(), [3,6,5,4,3][i]);
        await jumps.last().click();
        await page.waitForFunction(() => {
          const v = document.querySelector('#ops-player');
          return v.currentTime > 4 && v.readyState >= 2;
        });
        await player.evaluate(v => v.pause());
        await page.locator('.ops-tour__transcript summary').click();
        assert.ok((await page.locator('[data-ops-transcript]').innerText()).length > 70);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      }
      for (const path of ['', '/mommy-and-daddy-break', '/retro-glam']) {
        await page.goto(site + '/vnv-events-productions' + path + '/', { waitUntil: 'domcontentloaded' });
        assert.equal(await page.locator('a[href*="operational-software"],a[href*="operations.vnvevents.com"]').count(), 0);
      }
      await page.goto(site + '/operational-software/?role=unknown', { waitUntil: 'domcontentloaded' });
      assert.match(await page.locator('#ops-player source').getAttribute('src'), /organization-administrator[.]mp4$/);
      assert.match(await page.locator('link[rel="canonical"]').getAttribute('href'), /vnvevents[.]com\/operational-software\/$/);
      assert.ok(await page.getByRole('link', { name: 'Email VNV for an invitation' }).isVisible());
      await page.close();
    }
    console.log('Both viewports: four service entries, five role videos with chapters and transcripts, three isolated concepts, unknown role fallback, invitation and canonical passed.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
