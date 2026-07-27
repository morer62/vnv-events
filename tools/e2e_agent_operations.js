const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error') errors.push(message.text());
  });
  const login = await page.request.post(`${base}/login`, {
    form: { email: process.env.VNV_TEST_EMAIL || 'info@vnvevents.com', password: process.env.VNV_TEST_PASSWORD || '12345' }
  });
  await login.body();

  const results = [];
  const only = process.env.VNV_AGENT_ONLY || '';
  const scheduled = ['estimate_follow_up', 'order_auditor', 'content_refresh', 'operations_risk', 'lead_qualification', 'reputation', 'short_video'];
  for (const key of scheduled) {
    if (only && only !== key) continue;
    const response = await page.request.post(`${base}/panel/agents/detail?key=${key}`, {
      form: { agent_key: key, action: 'run' },
      timeout: 60000
    });
    await response.body();
    results.push({ key, status: response.status(), finalUrl: response.url() });
  }

  const contextual = [
    { key: 'event_brief', values: { order_id: 'first' } },
    { key: 'client_concierge', values: { order_id: 'first', question: 'What confirmed information should the client know before the event?' } },
    { key: 'post_event', values: { order_id: 'first' } },
    { key: 'meta_lead_estimator', values: { lead_id: 'first' } },
    { key: 'social_publisher', values: { content_id: 'first', 'networks[]': ['facebook', 'instagram'] } },
    { key: 'instagram_carousel', values: { content_id: 'first' } },
    { key: 'blog_writer', values: { content_id: 'first', provider: 'openai', instructions: 'Improve clarity and SEO while preserving every verified fact.' } }
  ];
  for (const test of contextual) {
    if (only && only !== test.key) continue;
    await page.goto(`${base}/panel/agents/detail?key=${test.key}`, { waitUntil: 'domcontentloaded' });
    const form = { agent_key: test.key, action: 'run' };
    let unavailable = false;
    for (const [name, value] of Object.entries(test.values)) {
      if (value === 'first') {
        const option = page.locator(`select[name="${name}"] option`).filter({ hasNotText: /^Select/ }).first();
        if (!await option.count()) {
          unavailable = true;
          break;
        }
        form[name] = await option.getAttribute('value');
      } else {
        form[name] = value;
      }
    }
    if (unavailable) {
      results.push({ key: test.key, skipped: 'No compatible source data' });
      continue;
    }
    if (test.key === 'social_publisher') {
      await page.locator('select[name="content_id"]').selectOption(form.content_id);
      await page.locator('input[name="networks[]"][value="facebook"]').check();
      await page.locator('input[name="networks[]"][value="instagram"]').check();
      await page.locator('form[data-social-run] button[type="submit"]').click();
      await page.waitForLoadState('domcontentloaded');
      results.push({ key: test.key, status: 200, finalUrl: page.url(), submittedInBrowser: true });
      continue;
    }
    const response = await page.request.post(`${base}/panel/agents/detail?key=${test.key}`, { form, timeout: 120000 });
    await response.body();
    results.push({ key: test.key, status: response.status(), finalUrl: response.url() });
  }

  await page.goto(`${base}/panel/agents/approvals`, { waitUntil: 'domcontentloaded' });
  const approvalUi = {
    visibleCards: await page.locator('.ac-card').count(),
    hasSearch: await page.locator('.ac-search input[name="q"]').count() === 1,
    hasSelectAll: await page.locator('[data-select-all]').count() === 1,
    pagination: await page.locator('.ac-pagination').count()
  };
  fs.mkdirSync('test-results/agent-operations', { recursive: true });
  await page.screenshot({ path: 'test-results/agent-operations/approval-center.png', fullPage: true });
  fs.writeFileSync('test-results/agent-operations/report.json', JSON.stringify({ results, approvalUi, errors }, null, 2));
  console.log(JSON.stringify({ results, approvalUi, errors }, null, 2));
  await browser.close();
  if (errors.length || results.some(item => item.status >= 400) || !approvalUi.hasSearch || !approvalUi.hasSelectAll || approvalUi.visibleCards > 30) process.exitCode = 1;
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
