const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const vendor = process.env.COMPOSER_VENDOR_DIR || path.join(root, 'vendor');
const endpoint = '/api/public/event-request';
const cases = [
  ['https://vnv.test', '/', undefined],
  ['https://vnv.test', '/event-production', endpoint],
  ['https://vnv.test', '/corporate-events/', endpoint],
  ['https://vnv.test', '/blog/example/', undefined],
  ['http://localhost/vnv-events', '/vnv-events/index.php?url=event-production', endpoint],
  ['http://localhost/vnv-events', '/vnv-events/corporate-events/', undefined],
  ['http://localhost/vnv-events', '/vnv-events/', 'http://localhost/vnv-events' + endpoint],
];

function renderModal(base, action) {
  const render = String.raw`
    require $argv[1];
    $_ENV['APP_URL'] = $argv[2];
    $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader('src/views'));
    $twig->addFunction(new Twig\TwigFunction('path', [App\Utils\LocationUtils::class, 'assetFor']));
    echo $twig->render('templates/components/publicEventRequestModal.html.twig', isset($argv[3]) ? ['request_form_action' => $argv[3]] : []);
  `;
  const args = ['-r', render, '--', path.resolve(vendor, 'autoload.php'), base];
  if (action) args.push(action);
  return execFileSync('php', args, { cwd: root, encoding: 'utf8' });
}

(async () => {
  const browser = await chromium.launch();
  try {
    for (const [base, route, action] of cases) {
      const url = new URL(route, base).href;
      const html = '<!doctype html><button data-vnv-open-request>Plan event</button>' + renderModal(base, action);
      for (const javaScriptEnabled of [false, true]) {
        const page = await browser.newPage({ javaScriptEnabled });
        await page.route('**/*', request => request.request().url() === url
          ? request.fulfill({ contentType: 'text/html', body: html }) : request.abort());
        await page.goto(url);
        if (javaScriptEnabled) {
          await page.locator('[data-vnv-open-request]').click();
          assert.equal(await page.locator('#vnvRequestModal').getAttribute('aria-hidden'), 'false');
        }
        const destination = await page.locator('#vnvRequestForm').evaluate(form => form.action);
        assert.equal(destination, base + endpoint, `Form destination on ${url} (JS=${javaScriptEnabled})`);
        await page.close();
      }
    }
    console.log(`Validated ${cases.length} page URLs with JavaScript enabled and disabled.`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
