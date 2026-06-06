<?php

use App\Repositories\LocationPagesRepository;
use App\Services\PublicSeoService;
use App\Utils\TemplateResponse;

$repo = new LocationPagesRepository();

$url = $_GET['url'] ?? '';
$urlParts = array_values(array_filter(explode('/', trim($url, '/'))));
$slug = end($urlParts) ?: null;

if (!$slug) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$page = $repo->getPublishedBySlug($slug);

if (!$page) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$page->gallery = !empty($page->gallery_json) ? json_decode($page->gallery_json, true) : [];
$page->faqs = !empty($page->faq_json) ? json_decode($page->faq_json, true) : [];
$page->dynamic_blocks = !empty($page->dynamic_blocks_json) ? json_decode($page->dynamic_blocks_json, true) : [];
$page->schema = !empty($page->schema_json) ? json_decode($page->schema_json, true) : null;
$nearbyPages = array_values(array_filter($repo->getAllPublished(), function ($item) use ($page) {
    return ($item->slug ?? '') !== ($page->slug ?? '') && (int)($item->is_indexable ?? 1) === 1;
}));
$nearbyPages = array_slice($nearbyPages, 0, 6);

if (empty($page->dynamic_blocks) && ($page->template_key ?? '') === 'location-home-luxe') {
    $page->dynamic_blocks = [
        [
            'type' => 'info',
            'title' => 'Quick Information',
            'items' => [
                ['label' => 'Phone', 'value' => '+1 305-204-5427'],
                ['label' => 'Email', 'value' => 'info@vnvevents.com'],
                ['label' => 'Hours', 'value' => 'Mon - Fri 10 AM - 5 PM'],
            ]
        ],
        [
            'type' => 'testimonials',
            'title' => 'What Clients Say',
            'enabled' => true,
            'items' => [
                ['quote' => 'VNV Events made our wedding weekend feel like a luxury production.', 'name' => 'Sofia M.', 'role' => 'Bride'],
                ['quote' => 'Every detail felt intentional and elegant from start to finish.', 'name' => 'Carla R.', 'role' => 'Corporate Client']
            ]
        ],
        [
            'type' => 'map',
            'title' => 'Address',
            'address' => '10258 NW 47th St, Sunrise, FL 33351'
        ]
    ];
}

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'page' => $page,
    'nearby_pages' => $nearbyPages,
    'internal_links' => PublicSeoService::defaultInternalLinks(),
    'seo' => PublicSeoService::locationSeo($page),
    'schemaJson' => PublicSeoService::locationSchema($page, is_array($page->faqs) ? $page->faqs : []),
    'show_whatsapp' => true
]);
