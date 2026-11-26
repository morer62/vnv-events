const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = process.env.VNV_QA_BASE || 'http://localhost/vnv-events/index.php?url=';
const routes = [
  ['event-production', 'full-production'],
  ['nonprofit-partnerships', 'cause-on-cue'],
  ['sponsor-activation-production', 'brand-activation'],
  ['corporate-events', 'corporate-events'],
  ['vnv-events-productions', 'original-experiences'],
  ['vnv-events-productions/retro-glam', 'retro-glam'],
  ['vnv-events-productions/mommy-and-daddy-break', 'morning-club'],
];

async function testFilm(context, route, slug, conserveMotion) {
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  try {
    const url = new URL(base);
    url.searchParams.set('url', route);
    const response = await page.goto(url.toString(), { waitUntil: 'domcontentloaded' });
    assert.equal(response.status(), 200);
    const trigger = page.locator('[data-vnv-watch-film]');
    const preview = page.locator('[data-vnv-preview] video');
    const film = page.locator(`#${slug}-film`);
    const player = film.locator('video[controls]');
    await trigger.waitFor();
    assert.equal(await trigger.getAttribute('href'), `#${slug}-film`);
    const hasPreview = slug !== 'retro-glam';
    assert.equal(await preview.count(), hasPreview ? 1 : 0);
    assert.equal(await player.evaluate(video => video.paused && video.currentTime === 0), true, 'narration started before interaction');

    if (hasPreview) {
      assert.equal(await preview.evaluate(video => video.muted), true);
      if (conserveMotion) {
        assert.equal(await preview.evaluate(video => video.paused && !video.getAttribute('src')), true, 'preview loaded despite user preference');
      } else {
        await page.waitForFunction(() => document.querySelector('[data-vnv-preview] video').currentTime > 0);
      }
    }

    await trigger.click();
    await page.waitForFunction(() => {
      const video = document.querySelector('.ve-film video');
      return video.currentTime > 0.25 && video.webkitAudioDecodedByteCount > 0;
    });
    assert.equal(await player.evaluate(video => !video.muted && video.volume > 0 && !video.paused && video.videoWidth > 0), true, 'expected an audible, decoded film');
    if (hasPreview) assert.equal(await preview.evaluate(video => video.paused), true, 'preview still playing');
    assert.equal(new URL(page.url()).hash, `#${slug}-film`);
    await page.waitForFunction(() => {
      const rect = document.querySelector('.ve-film video').getBoundingClientRect();
      return rect.top < innerHeight && rect.bottom > 0;
    });
    const playerReceivedFocus = await player.evaluate(video => document.activeElement === video);

    const transcript = film.locator('details');
    const summary = transcript.locator('summary');
    assert.equal(await transcript.evaluate(node => node.open), false);
    await summary.focus();
    await summary.press('Enter');
    await page.waitForFunction(() => {
      const transcript = document.querySelector('.ve-film__transcript');
      return transcript.open && !transcript.querySelector('p a');
    });
    await player.evaluate(video => { video.textTracks[0].mode = 'showing'; });
    await page.waitForFunction(() => document.querySelector('.ve-film video').textTracks[0].cues?.length > 0);
    const captionText = await player.evaluate(video => Array.from(video.textTracks[0].cues, cue => cue.text).join(' '));
    const transcriptText = await transcript.locator('p').textContent();
    assert.equal(transcriptText.trim(), captionText.replace(/\s+/g, ' ').trim(), 'transcript differs from native English captions');
    assert.equal(playerReceivedFocus, true, 'keyboard focus did not reach the playing film');

    const nextStep = film.locator('.vnv-film__copy .vnvp-button');
    const requestTypes = { 'full-production': 'PRODUCTION', 'corporate-events': 'CORPORATE', 'brand-activation': 'ACTIVATION' };
    if (requestTypes[slug]) {
      await nextStep.click();
      const modal = page.locator('#vnvQuickRequestModal');
      assert.equal(await modal.getAttribute('aria-hidden'), 'false');
      assert.equal(await modal.locator('[name="request_type"]').inputValue(), requestTypes[slug]);
      assert.equal(await modal.locator('[name="form_source"]').inputValue(), await nextStep.getAttribute('data-quick-request-source'));
      assert.equal(await player.evaluate(video => video.paused), true, 'narration continued behind the form');
    } else {
      const targets = { 'cause-on-cue': '#application', 'original-experiences': '#original-experiences', 'morning-club': '#waitlist', 'retro-glam': '#priority-list' };
      await nextStep.click();
      await page.locator(targets[slug]).waitFor({ state: 'visible' });
      assert.equal(new URL(page.url()).hash, targets[slug]);
      assert.equal(await page.locator(targets[slug]).count(), 1, 'film links to a missing section');
    }
  } finally {
    await page.close();
  }
}

(async () => {
  const browser = await chromium.launch({ channel: process.env.VNV_QA_BROWSER_CHANNEL || 'chrome' });
  const errors = [];
  try {
    for (const scenario of [
      { name: 'desktop', viewport: { width: 1440, height: 900 }, reducedMotion: 'no-preference' },
      { name: 'mobile reduced motion', viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' },
    ]) {
      const context = await browser.newContext({ viewport: scenario.viewport, reducedMotion: scenario.reducedMotion });
      try {
        for (const [route, slug] of routes) {
          try {
            await testFilm(context, route, slug, scenario.reducedMotion === 'reduce');
            console.log(`PASS ${scenario.name} / ${route}`);
          } catch (error) {
            errors.push(`${scenario.name} / ${route}: ${error.message}`);
            console.error(`FAIL ${errors.at(-1)}`);
          }
        }
      } finally {
        await context.close();
      }
    }
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
