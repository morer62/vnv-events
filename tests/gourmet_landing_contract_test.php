<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CateringLandingProductService;
use App\Services\GourmetLandingAttributionService;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$variable = (object)['product_type' => 'VARIABLE', 'price' => 0];
$pastaVariations = [
    (object)['name' => 'Pasta Station', 'price' => 19.00, 'promo_price' => 19.00],
    (object)['name' => 'Additional Topping', 'price' => 3.00, 'promo_price' => 3.00],
    (object)['name' => 'Pasta Station + Appetizers', 'price' => 35.00, 'promo_price' => 35.00],
];
$pastaConfig = ['fallback_price' => 19.00, 'base_variations' => ['Pasta Station']];
$assert(CateringLandingProductService::resolveBasePrice($variable, $pastaVariations, $pastaConfig) === 19.0, 'Pasta add-on changed the base price.');

$appetizerVariations = [
    (object)['name' => 'THE SIGNATURE COLLECTION (Classic Variety)', 'price' => 21.77, 'promo_price' => 21.77],
    (object)['name' => 'THE OCEAN BLUE COLLECTION (Sushi Experience)', 'price' => 21.77, 'promo_price' => 21.77],
    (object)['name' => 'Extra Bite (+1 appetizer per person)', 'price' => 3.00, 'promo_price' => 3.00],
];
$appetizerConfig = [
    'fallback_price' => 21.77,
    'base_variations' => [
        'THE SIGNATURE COLLECTION (Classic Variety)',
        'THE OCEAN BLUE COLLECTION (Sushi Experience)',
    ],
];
$assert(CateringLandingProductService::resolveBasePrice($variable, $appetizerVariations, $appetizerConfig) === 21.77, 'Extra Bite changed the appetizer base price.');

$fixed = (object)['product_type' => 'FIXED', 'price' => 19.00];
$assert(CateringLandingProductService::resolveBasePrice($fixed, [], ['fallback_price' => 17, 'base_variations' => []]) === 19.0, 'Fixed Store price was not used.');

$message = CateringLandingProductService::buildWhatsappMessage([
    'service_name' => 'Pasta Station',
    'store_price_label' => 'From $19 per guest',
    'ref_code' => 'GA-PASTA-7K3',
], ['event_date' => '2026-12-20', 'guest_count' => '41–75', 'zip' => '33351']);
$assert(str_contains($message, 'Pasta Station'), 'WhatsApp message is missing the service.');
$assert(str_contains($message, 'GA-PASTA-7K3'), 'WhatsApp message is missing the reference.');
$assert(str_contains($message, '33351'), 'WhatsApp message is missing ZIP.');

$assert(GourmetLandingAttributionService::guestBucketMinimum('25-40') === 25, 'Guest bucket parsing failed.');
$assert(GourmetLandingAttributionService::guestBucketMinimum('fewer-than-25') === 1, 'Under-minimum bucket parsing failed.');
$assert(GourmetLandingAttributionService::isSouthFloridaZip('33351'), 'Broward ZIP should qualify.');
$assert(!GourmetLandingAttributionService::isSouthFloridaZip('90210'), 'Out-of-area ZIP should not qualify.');
$assert(GourmetLandingAttributionService::safeLandingPath('https://vnvevents.com/pasta-station-catering-south-florida/?x=1') === '/pasta-station-catering-south-florida/', 'Safe landing path failed.');
$assert(GourmetLandingAttributionService::safeLandingPath('https://evil.example/') === '/vnv-gourmet/', 'Unsafe empty path did not use the hub fallback.');

$template = file_get_contents(dirname(__DIR__) . '/src/views/templates/pages/cateringServiceLanding.twig');
foreach (['call_click', 'whatsapp_click', 'generate_lead', 'gclid', 'gbraid', 'wbraid', 'SameSite=Lax', 'data-cl-form'] as $needle) {
    $assert(str_contains($template, $needle), 'Landing template missing contract: ' . $needle);
}
$assert(is_file(dirname(__DIR__) . '/src/views/public/pages/appetizer-catering-south-florida/index.php'), 'Appetizer landing is missing.');

$leadEndpoint = file_get_contents(dirname(__DIR__) . '/src/views/api/public/gourmet-lead/index.php');
foreach (['beginTransaction', 'commit()', 'rollback()', "'setup_minutes' => 0"] as $needle) {
    $assert(str_contains($leadEndpoint, $needle), 'Lead endpoint missing atomic persistence contract: ' . $needle);
}
$clickEndpoint = file_get_contents(dirname(__DIR__) . '/src/views/api/public/gourmet-track/index.php');
$assert(str_contains($clickEndpoint, "'setup_minutes' => 0"), 'WhatsApp tracking must satisfy Ophyra setup_minutes.');
$serviceSource = file_get_contents(dirname(__DIR__) . '/src/Services/CateringLandingProductService.php');
$assert(str_contains($serviceSource, "'canonical' => (string)(\$page['canonical_url']"), 'Landing SEO view data must provide the canonical URL explicitly.');

echo "Gourmet landing contract OK: {$assertions} assertions\n";
