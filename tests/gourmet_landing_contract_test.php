<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CateringLandingProductService;
use App\Services\GourmetLandingAttributionService;
use App\Services\GourmetLeadRateLimiter;
use App\Services\GourmetOfflineConversionExporter;

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
$assert(CateringLandingProductService::resolveBasePrice($variable, $pastaVariations, ['fallback_price' => 19.00, 'base_variations' => ['Pasta Station']]) === 19.0, 'Pasta add-on changed the base price.');

$appetizerVariations = [
    (object)['name' => 'THE SIGNATURE COLLECTION (Classic Variety)', 'price' => 21.77, 'promo_price' => 21.77],
    (object)['name' => 'THE OCEAN BLUE COLLECTION (Sushi Experience)', 'price' => 21.77, 'promo_price' => 21.77],
    (object)['name' => 'Extra Bite (+1 appetizer per person)', 'price' => 3.00, 'promo_price' => 3.00],
];
$appetizerConfig = ['fallback_price' => 21.77, 'base_variations' => [
    'THE SIGNATURE COLLECTION (Classic Variety)',
    'THE OCEAN BLUE COLLECTION (Sushi Experience)',
]];
$assert(CateringLandingProductService::resolveBasePrice($variable, $appetizerVariations, $appetizerConfig) === 21.77, 'Extra Bite changed the appetizer base price.');

$fixed = (object)['product_type' => 'FIXED', 'price' => 19.00];
$assert(CateringLandingProductService::resolveBasePrice($fixed, [], ['fallback_price' => 17, 'base_variations' => []]) === 19.0, 'Fixed Store price was not used.');

$message = CateringLandingProductService::buildWhatsappMessage([
    'service_name' => 'Pasta Station',
    'store_price_label' => 'From $19 per guest',
    'ref_code' => 'GA-PASTA-7K3',
], ['event_date' => '2026-12-20', 'guest_count' => '41-75', 'zip' => '33351']);
foreach (['Pasta Station', 'GA-PASTA-7K3', '33351', '2026-12-20'] as $needle) {
    $assert(str_contains($message, $needle), 'WhatsApp message is missing: ' . $needle);
}

$assert(GourmetLandingAttributionService::guestBucketMinimum('25-40') === 25, 'Guest bucket parsing failed.');
$assert(GourmetLandingAttributionService::guestBucketMinimum('fewer-than-25') === 1, 'Under-minimum bucket parsing failed.');
$assert(GourmetLandingAttributionService::classifyZip('33351')['in_area'] === true, 'Broward ZIP should qualify.');
foreach (['33040', '33455', '33440'] as $zip) {
    $assert(GourmetLandingAttributionService::classifyZip($zip)['in_area'] === false, $zip . ' must be a verified external ZIP.');
}
$assert(GourmetLandingAttributionService::classifyZip('90210')['status'] === 'unknown', 'Unreviewed ZIP must remain unknown.');
$assert(GourmetLandingAttributionService::safeLandingPath('https://vnvevents.com/pasta-station-catering-south-florida/?x=1') === '/pasta-station-catering-south-florida/', 'Safe landing path failed.');
$assert(GourmetLandingAttributionService::safeLandingPath('https://evil.example/') === '/vnv-gourmet/', 'Foreign landing host did not use the hub fallback.');

$controlledNow = new DateTimeImmutable('2026-11-06 12:00:00', GourmetLandingAttributionService::eventTimezone());
$assert(GourmetLandingAttributionService::minimumEventDate($controlledNow) === '2026-11-07', 'Minimum event date must be tomorrow in Eastern Time.');
$assert(!GourmetLandingAttributionService::isFutureEventDate('2026-11-06', $controlledNow), 'Today must not be accepted as a future event date.');
$assert(GourmetLandingAttributionService::isFutureEventDate('2026-11-07', $controlledNow), 'Tomorrow must be accepted.');
$assert(str_ends_with(GourmetLandingAttributionService::conversionTime($controlledNow), '-05:00'), 'Conversion time must include the Eastern offset.');

$assert(GourmetLeadRateLimiter::decision(4, 19)['allowed'], 'Limiter blocked a request before either threshold.');
$assert(!GourmetLeadRateLimiter::decision(5, 5)['allowed'], 'Ten-minute threshold was not enforced.');
$assert(GourmetLeadRateLimiter::decision(5, 5)['reason'] === 'ten_minute_limit', 'Ten-minute reason mismatch.');
$assert(GourmetLeadRateLimiter::decision(0, 20)['reason'] === 'daily_limit', 'Daily threshold was not enforced.');
$assert(GourmetLeadRateLimiter::resolveClientIp(['REMOTE_ADDR' => '198.51.100.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']) === '198.51.100.2', 'Untrusted forwarded IP must be ignored.');

$qualifiedPayload = json_encode([
    'qualified_lead' => true,
    'conversion_time' => '2026-11-06 10:00:00-05:00',
    'attribution' => ['gclid' => 'QA-GCLID'],
]);
$recentLeadTime = new DateTimeImmutable('now', GourmetLandingAttributionService::eventTimezone());
$leads = [
    ['id' => 11, 'status' => 'NEW', 'availability_status' => 'QUALIFIED', 'payload_json' => $qualifiedPayload, 'created_at' => $recentLeadTime->format('Y-m-d H:i:s'), 'converted_order_id' => 501],
    ['id' => 12, 'status' => 'TEST', 'availability_status' => 'QUALIFIED', 'payload_json' => $qualifiedPayload, 'created_at' => $recentLeadTime->format('Y-m-d H:i:s')],
    ['id' => 13, 'status' => 'NEW', 'availability_status' => 'QUALIFIED', 'payload_json' => $qualifiedPayload, 'created_at' => (new DateTimeImmutable('-91 days'))->format('Y-m-d H:i:s')],
    ['id' => 14, 'status' => 'NEW', 'availability_status' => 'QUALIFIED', 'payload_json' => json_encode(['qualified_lead' => true, 'attribution' => []]), 'created_at' => $recentLeadTime->format('Y-m-d H:i:s')],
];
$snapshot = static fn(int $orderId): array => [
    'order' => (object)['id' => $orderId, 'status' => 'CONFIRMED'],
    'payments' => [(object)['net_amount' => 500, 'paid_at' => '2026-11-06 11:30:00']],
    'totals' => ['net_paid' => 500, 'total' => 950],
];
$rows = GourmetOfflineConversionExporter::candidates($leads, $snapshot);
$assert(count($rows) === 2, 'Exporter must create qualified and booked rows while excluding test, expired and unattributed leads.');
$assert($rows[0]['click_id_type'] === 'gclid' && $rows[0]['click_id'] === 'QA-GCLID', 'Exporter lost the click identifier.');
$assert((float)$rows[1]['value'] === 950.0, 'Booked conversion must carry the order value.');
$existing = [$rows[0]['conversion_action'] . '|' . $rows[0]['order_id'] => true, $rows[1]['conversion_action'] . '|' . $rows[1]['order_id'] => true];
$assert(GourmetOfflineConversionExporter::candidates([$leads[0]], $snapshot, $existing) === [], 'Exporter is not idempotent.');
$csv = GourmetOfflineConversionExporter::csv($rows);
foreach (['Google Click ID', 'Conversion Name', 'Conversion Currency', 'QA-GCLID'] as $needle) {
    $assert(str_contains($csv, $needle), 'Offline CSV is missing: ' . $needle);
}

$template = file_get_contents(dirname(__DIR__) . '/src/views/templates/pages/cateringServiceLanding.twig');
foreach (['call_click', 'whatsapp_click', 'generate_lead', 'gclid', 'gbraid', 'wbraid', 'SameSite=Lax', 'vnv_ads_session', 'minimum_event_date', 'data-cl-form'] as $needle) {
    $assert(str_contains($template, $needle), 'Landing template missing contract: ' . $needle);
}
$comparison = file_get_contents(dirname(__DIR__) . '/src/views/public/pages/catering-stations-south-florida/index.twig');
foreach (['data-cs-form', 'not-sure-yet', 'vnv_ads_session', 'minimum_event_date'] as $needle) {
    $assert(str_contains($comparison, $needle), 'Comparison Ads mode missing contract: ' . $needle);
}
$assert(is_file(dirname(__DIR__) . '/src/views/public/pages/appetizer-catering-south-florida/index.php'), 'Appetizer landing is missing.');

$leadEndpoint = file_get_contents(dirname(__DIR__) . '/src/views/api/public/gourmet-lead/index.php');
foreach (['beginTransaction', 'commit()', 'rollback()', 'GourmetLeadRateLimiter', 'http_response_code(429)', "'setup_minutes' => 0", "'is_test' =>"] as $needle) {
    $assert(str_contains($leadEndpoint, $needle), 'Lead endpoint missing contract: ' . $needle);
}
$clickEndpoint = file_get_contents(dirname(__DIR__) . '/src/views/api/public/gourmet-track/index.php');
$assert(str_contains($clickEndpoint, "'setup_minutes' => 0"), 'WhatsApp tracking must satisfy Ophyra setup_minutes.');
$assert(str_contains($clickEndpoint, "'is_test' =>"), 'WhatsApp tracking must preserve the test marker.');
$purgeCommand = file_get_contents(dirname(__DIR__) . '/bin/purge-gourmet-test-leads.php');
foreach (["status='TEST'", "empty(\$payload['is_test'])", '--confirm=PURGE-GOURMET-TEST-LEADS', 'beginTransaction', 'rollback'] as $needle) {
    $assert(str_contains($purgeCommand, $needle), 'Safe test-lead purge is missing: ' . $needle);
}

$serviceSource = file_get_contents(dirname(__DIR__) . '/src/Services/CateringLandingProductService.php');
$assert(str_contains($serviceSource, "'canonical' => (string)(\$page['canonical_url']"), 'Landing SEO view data must provide the canonical URL explicitly.');
$assert(!str_contains($serviceSource, "'meal_box_price' => 12.50"), 'Corporate meal-box price remains hardcoded in PHP.');

$seoSources = file_get_contents(dirname(__DIR__) . '/src/Services/PublicSeoService.php')
    . file_get_contents(dirname(__DIR__) . '/src/views/public/pages/faq/index.php');
$assert(!str_contains($seoSources, "'@type' => 'AggregateRating'"), 'Self-published AggregateRating remains in global schema.');
$assert(!str_contains($seoSources, "'@type' => 'Review'"), 'Self-published Review remains in global schema.');

echo "Gourmet landing contract OK: {$assertions} assertions\n";
