<?php

use App\Services\GourmetStorefrontService;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$data = (new GourmetStorefrontService())->build(AvomealContext::ownerId(), SiteContext::siteKey());

echo TemplateResponse::render(dirname(__DIR__) . '/vnv-events-to-go/index.twig', [
    'categories' => $data['categories'],
    'gourmet_settings' => $data['settings'],
    'delivery_zones' => $data['zones'],
    'delivery_windows' => $data['windows'],
    'lowest_box_price' => $data['lowest_box_price'],
    'lowest_tray_price' => $data['lowest_tray_price'],
    'store_hero_image' => $data['hero_image'],
    'seasonal_products' => $data['seasonal_products'],
    'schemaJson' => [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'FoodEstablishment', 'name' => 'VNV Gourmet To Go', 'url' => 'https://vnvevents.com/gourmet-to-go', 'telephone' => '+1-305-204-5427', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $data['settings']['pickup_address_1'] ?? '10258 NW 47th St', 'addressLocality' => $data['settings']['pickup_city'] ?? 'Sunrise', 'addressRegion' => 'FL', 'postalCode' => $data['settings']['pickup_zip'] ?? '33351', 'addressCountry' => 'US']],
            ['@type' => 'FAQPage', 'mainEntity' => [
                ['@type' => 'Question', 'name' => 'How far ahead do I need to order?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Standard orders require the configured lead time. Holiday menus may have an earlier deadline.']],
                ['@type' => 'Question', 'name' => 'Can I pick up?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Pickup is available at the VNV kitchen during configured pickup windows.']],
            ]],
        ],
    ],
]);
