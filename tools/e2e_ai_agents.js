const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const base = process.env.VNV_TEST_BASE || 'http://localhost/vnv-events';
  const out = path.resolve('test-results/ai-agents');
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: process.env.VNV_TEST_BROWSER || 'chrome' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  page.setDefaultTimeout(10000);
  page.setDefaultNavigationTimeout(10000);
  const report = { base, startedAt: new Date().toISOString(), pages: [], console: [], pageErrors: [] };

  page.on('console', message => {
    if (['error', 'warning'].includes(message.type())) {
      report.console.push({ type: message.type(), url: page.url(), text: message.text() });
    }
  });
  page.on('pageerror', error => report.pageErrors.push({ url: page.url(), text: error.message }));

  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  console.log('Loaded login');
  const loginResponse = await page.request.post(`${base}/login`, {
    form: {
      email: process.env.VNV_TEST_EMAIL || 'info@vnvevents.com',
      password: process.env.VNV_TEST_PASSWORD || '12345'
    },
    timeout: 20000
  });
  await loginResponse.body();
  await page.goto(`${base}/panel/home`, { waitUntil: 'domcontentloaded' });
  console.log(`Login result: ${page.url()} (${loginResponse.status()})`);
  if (/\/login(?:\?|$)/.test(page.url()) || !loginResponse.ok()) {
    throw new Error(`Login did not establish a panel session: ${page.url()}`);
  }
  report.loginUrl = page.url();

  const routes = [
    ['home', '/panel/home'],
    ['agents', '/panel/agents'],
    ['approvals', '/panel/agents/approvals'],
    ['conversations', '/panel/agents/conversations'],
    ['growth-hub', '/panel/growth-hub'],
    ['distribution-social', '/panel/growth-hub/distribution?mode=social'],
    ['distribution-carousel', '/panel/growth-hub/distribution?mode=carousel'],
    ['distribution-short-video', '/panel/growth-hub/distribution?mode=short-video'],
    ['video-studio', '/panel/growth-hub/video-studio'],
    ['editorial-settings', '/panel/growth-hub/settings'],
    ['blog-agent', '/panel/agents/detail?key=blog_writer'],
    ['estimate-agent', '/panel/agents/detail?key=estimate_followup'],
    ['social-agent', '/panel/agents/detail?key=social_publisher']
  ];

  for (const [name, route] of routes) {
    console.log(`Checking ${name}`);
    const response = await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(800);
    const body = (await page.locator('body').innerText()).trim();
    const badText = /(fatal error|uncaught exception|parse error|warning:|database error)/i.test(body);
    const loginBounce = /\/login(?:\?|$)/.test(page.url());
    const item = {
      name,
      requested: route,
      finalUrl: page.url(),
      status: response ? response.status() : null,
      title: await page.title(),
      bodyLength: body.length,
      badText,
      loginBounce,
      h1: await page.locator('h1').allTextContents()
    };
    report.pages.push(item);
    await page.screenshot({ path: path.join(out, `${name}.png`) });
  }

  report.finishedAt = new Date().toISOString();
  fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
  await browser.close();
  const failures = report.pages.filter(p => p.status >= 400 || p.badText || p.loginBounce);
  console.log(JSON.stringify({ loginUrl: report.loginUrl, pages: report.pages.length, failures, console: report.console, pageErrors: report.pageErrors }, null, 2));
  process.exitCode = failures.length || report.pageErrors.length ? 1 : 0;
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
