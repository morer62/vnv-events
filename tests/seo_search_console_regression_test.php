<?php

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Services\PublicSeoService;
use App\Services\SeoFilesGeneratorService;
use App\Utils\TemplateResponse;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$normalized = PublicSeoService::normalizeArticleBodyHeadings(
    '<h1 class="article-heading">Repeated title</h1><p>Body</p><h2>Existing section</h2>'
);
$assert(!preg_match('/<\/?h1\b/i', $normalized), 'Embedded blog H1 was not removed from the public outline.');
$assert(str_contains($normalized, '<h2 class="article-heading">Repeated title</h2>'), 'Embedded H1 attributes were not preserved when demoted.');
$assert(str_contains($normalized, '<h2>Existing section</h2>'), 'Existing body headings changed unexpectedly.');

$root = dirname(__DIR__);
$header = file_get_contents($root . '/src/views/templates/layout/publicHeaderVnv.html.twig');
$footer = file_get_contents($root . '/src/views/templates/layout/publicFooterVnv.html.twig');
$assert(substr_count($header, "path('/vnv-gourmet')") >= 2, 'Desktop and mobile navigation must link to VNV Gourmet.');
$assert(!str_contains($header, "path('gourmet-to-go')"), 'Paused Gourmet To Go remains in the primary header.');
$assert(str_contains($footer, 'VNV Gourmet Catering'), 'Footer does not expose the active catering hub.');
$assert(str_contains($footer, 'https://maps.app.goo.gl/XE7zewaukQPHUwbFA'), 'Footer directions link is not the verified showroom map URL.');

$home = file_get_contents($root . '/src/views/public/app/planner-hub/index.twig');
$assert(str_contains($home, 'Welcome to VNV Events'), 'Homepage brand name is singular or incomplete.');
$assert(str_contains($home, "canonical: 'https://vnvevents.com/'"), 'Homepage does not declare its root canonical explicitly.');

$comparison = file_get_contents($root . '/src/views/public/pages/catering-stations-south-florida/index.twig');
$assert(!str_contains($comparison, 'built for this campaign'), 'Internal campaign language is visible on the catering comparison page.');
$assert(!str_contains($comparison, 'Campaign priorities'), 'Campaign planning terminology is visible to customers.');
$assert(!str_contains($comparison, 'show Gourmet To Go'), 'Paused Gourmet To Go is promised as an automatic alternative.');

$cateringLanding = file_get_contents($root . '/src/views/templates/pages/cateringServiceLanding.twig');
$assert(!str_contains($cateringLanding, 'four campaign stations'), 'Internal campaign language is visible on catering landings.');
$assert(!str_contains($cateringLanding, 'show Gourmet To Go'), 'Paused Gourmet To Go is promised on catering landings.');
$assert(str_contains($cateringLanding, '<span class="cl-minimum">Minimum: {{ page.minimum }}</span>'), 'Hero price and minimum do not have an explicit readable separator.');
$assert(str_contains($cateringLanding, '<small>Minimum: {{ page.minimum }}</small>'), 'Store price and minimum do not have an explicit readable separator.');

$seoService = file_get_contents($root . '/src/Services/PublicSeoService.php');
$assert(!str_contains($seoService, "'@type' => 'GeoCoordinates'"), 'Invalid unverified GeoCoordinates remain in organization schema.');
$assert(!str_contains($seoService, 'instagram.com/vnvevents/'), 'Organization schema points to the wrong Instagram identity.');
$assert(str_contains($seoService, 'instagram.com/vnv_events/'), 'Organization schema is missing the verified Instagram identity.');

$locationsController = file_get_contents($root . '/src/views/public/pages/locations/index.php');
$assert(str_contains($locationsController, 'get_sitemap_location_pages()'), 'Location directory has no published-sitemap fallback.');
$assert(str_contains($locationsController, "^/locations/([^/]+)/?$"), 'Location sitemap fallback is not restricted to existing location routes.');
$locationsTemplate = file_get_contents($root . '/src/views/public/pages/locations/index.twig');
$assert(!str_contains($locationsTemplate, "p.public_path|default('/locations/' ~ p.slug ~ '/')|trim('/')"), 'Location directory strips canonical trailing slashes from internal links.');

$legacyCateringRedirect = file_get_contents($root . '/src/views/public/pages/catering-delivery/index.php');
$assert(str_contains($legacyCateringRedirect, "pathFor('vnv-gourmet')"), 'Legacy catering delivery URL does not preserve authority with a base-aware 301.');

$seoGenerator = file_get_contents($root . '/src/Services/SeoFilesGeneratorService.php');
$assert(str_contains($seoGenerator, "'catering-delivery'"), 'Redirect-only catering delivery route remains eligible for the page sitemap.');
$assert(str_contains($seoGenerator, "'sucess-request'"), 'Noindex request confirmation route remains eligible for the page sitemap.');
$assert(str_contains($seoGenerator, "'/pages/catering-services/'"), 'Legacy catering placeholder remains eligible for the page sitemap.');

$kernel = file_get_contents($root . '/src/Kernel.php');
$assert(str_contains($kernel, "\$urlViews[1] === 'catering-services'"), 'Legacy catering placeholder route is not handled explicitly.');
$assert(str_contains($kernel, "LocationUtils::pathFor('vnv-gourmet')"), 'Legacy catering placeholder does not preserve authority with a 301.');

$landingService = file_get_contents($root . '/src/Services/CateringLandingProductService.php');
foreach (['pizza-station-catering-south-florida', 'paella-catering-south-florida', 'shawarma-station-catering-south-florida'] as $organicLanding) {
    $assert(str_contains($landingService, $organicLanding), 'Organic catering landing is missing from the shared product resolver: ' . $organicLanding);
}

$templateResponse = new ReflectionClass(TemplateResponse::class);
$extractSeo = $templateResponse->getMethod('extractStaticSeoBlocks');
$extractSeo->setAccessible(true);

$productionPages = [
    'flavor-fusion-fest' => 'Flavor Fusion Fest | VNV Events Productions',
    'mommy-and-daddy-break' => 'Mommy and Daddy Break | VNV Events Productions',
    'retro-glam' => 'Retro Glam Sessions | VNV Events Productions',
    'vanguard-business-gala' => 'Vanguard Business Gala | VNV Events Productions',
];
foreach ($productionPages as $slug => $expectedTitle) {
    $template = $root . '/src/views/public/pages/vnv-events-productions/' . $slug . '/index.twig';
    $productionSeo = $extractSeo->invoke(null, $template);
    $assert(($productionSeo['title'] ?? '') === $expectedTitle, 'Production page inherited the homepage title: ' . $slug);
    $assert(($productionSeo['canonical'] ?? '') === 'https://vnvevents.com/vnv-events-productions/' . $slug . '/', 'Production page canonical is missing or incorrect: ' . $slug);
}

$budgetTemplate = file_get_contents($root . '/src/views/public/pages/event-budget-calculator/index.twig');
foreach (['Event Budget Calculator', 'https://vnvevents.com/event-budget-calculator/', 'WebApplication', 'FAQPage', 'data-budget-form', 'data-result-per-guest'] as $needle) {
    $assert(str_contains($budgetTemplate, $needle), 'Budget calculator contract is missing: ' . $needle);
}
$assert(substr_count(strtolower($budgetTemplate), '<h1') === 1, 'Budget calculator must render exactly one H1.');
$assert(!preg_match('/(?:from|starting at)\s*\$\d+/i', $budgetTemplate), 'Budget calculator must not invent service prices.');

$staticSeo = $extractSeo->invoke(null, $root . '/src/views/public/pages/event-budget-calculator/index.twig');
$assert(($staticSeo['title'] ?? '') === 'Event Budget Calculator for South Florida | VNV Events', 'Static title was not shared with structured data.');
$assert(($staticSeo['canonical'] ?? '') === 'https://vnvevents.com/event-budget-calculator/', 'Static canonical was not shared with structured data.');

$repository = file_get_contents($root . '/src/Repositories/StoreProductsRepository.php');
$assert(
    str_contains($repository, 'product_role, is_addon_only'),
    'Store sitemap query does not expose add-on fields to the generator.'
);

$generator = new SeoFilesGeneratorService(sys_get_temp_dir());
$reflection = new ReflectionClass($generator);
$rank = $reflection->getMethod('rankLlmsEntries');
$rank->setAccessible(true);
$ranked = $rank->invoke($generator, [
    ['loc' => 'https://vnvevents.com/z-last/', 'title' => 'Newest alphabetical page', 'priority' => 0.9, 'lastmod' => '2026-11-07'],
    ['loc' => 'https://vnvevents.com/vnv-gourmet/', 'title' => 'VNV Gourmet', 'priority' => 0.8, 'lastmod' => '2026-01-01'],
]);
$assert(($ranked[0]['loc'] ?? '') === 'https://vnvevents.com/vnv-gourmet/', 'LLM discovery ordering ignored the commercial authority map.');

echo "SEO Search Console regression assertions passed: {$assertions}\n";
