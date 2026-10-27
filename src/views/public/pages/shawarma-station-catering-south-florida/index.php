<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'slug' => 'shawarma-station-catering-south-florida',
    'meta_title' => 'Shawarma Station Catering in South Florida | VNV Events',
    'meta_description' => 'Live Shawarma Station with chicken and beef, warm pita, fresh toppings, Mediterranean rice, tabbouleh, sauces, chef and serving staff. From $19 per guest.',
    'meta_keywords' => 'shawarma station catering South Florida, live shawarma catering Miami, shawarma bar Broward, event catering Palm Beach',
    'eyebrow' => 'Live Shawarma Station',
    'h1' => 'Shawarma Station catering in South Florida',
    'hero_copy' => 'Freshly carved shawarma, Mediterranean flavors and a live cooking experience for events in Miami-Dade, Broward and Palm Beach. Guests enjoy chicken and beef shawarma, warm pita, fresh toppings, signature sauces, rice and tabbouleh.',
    'hero_image' => 'assets/images/services/shawarma-station.webp',
    'secondary_image' => 'assets/images/services/shawarma-station.webp',
    'image_alt' => 'Live Shawarma Station buffet prepared for a South Florida event',
    'section_title' => 'Freshly carved, prepared and served live',
    'section_copy' => 'A professional chef and serving staff prepare the station while guests build their plates with warm pita, fresh toppings, signature sauces, Mediterranean rice and tabbouleh. VNV Events plans the setup around the guest count, timeline and venue requirements.',
    'included' => [
        'Chicken shawarma marinated with Mediterranean spices and tender, slow-roasted beef shawarma',
        'Warm pita bread',
        'Shredded lettuce, diced tomatoes and cucumbers, and red onions',
        'Garlic toum sauce, tahini sauce and classic hummus',
        'Mediterranean rice and tabbouleh salad',
        'Two hours of live service with a professional chef and serving staff',
        'Mediterranean-style station setup with disposable plates, napkins and cutlery',
        'Fresh toppings, bread and sauce display',
    ],
    'inclusion_note' => 'Final menu selections and any dietary accommodations are reviewed and confirmed in the written proposal.',
    'price' => 'From $19 p/p',
    'minimum' => '25-guest minimum',
    'price_note' => 'The base station is $19 per guest with a 25-guest minimum. Delivery, taxes, upgrades, venue requirements and the final total are confirmed in the written proposal.',
    'best_for' => [
        'Corporate lunches and office celebrations',
        'Birthdays and family milestones',
        'Weddings, rehearsal dinners and late-night bites',
        'Community, school and nonprofit gatherings',
    ],
    'choices' => [
        ['title' => 'Two proteins, served live', 'copy' => 'Mediterranean-spiced chicken and tender beef shawarma are freshly prepared and served during the two-hour service window.'],
        ['title' => 'Fresh toppings, sides and sauces', 'copy' => 'Warm pita, lettuce, tomatoes, cucumbers, red onions, garlic toum, tahini, hummus, Mediterranean rice and tabbouleh let guests build the plate they want.'],
        ['title' => 'A complete live-station experience', 'copy' => 'A professional chef and serving staff provide a Mediterranean-style setup, serving display, disposable plates, napkins and cutlery.'],
    ],
    'product_url' => 'https://vnvevents.com/product/shawarma-station/',
    'faqs' => [
        ['question' => 'How much does the Shawarma Station cost?', 'answer' => 'The base station is $19 per guest with a 25-guest minimum. Delivery, taxes and the final total are listed in the written proposal.'],
        ['question' => 'How long is the chef on site?', 'answer' => 'The package includes two hours of live-chef service. Ask about a different timeline when requesting your proposal.'],
        ['question' => 'Can VNV accommodate dietary needs?', 'answer' => 'Share dietary needs and allergies when you inquire. Each request is reviewed, and the written proposal states what can be accommodated.'],
        ['question' => 'Which areas does VNV serve?', 'answer' => 'VNV Events serves events across South Florida, including Miami-Dade, Broward and Palm Beach counties. Date availability and venue rules are confirmed before booking.'],
    ],
    'cta_title' => 'Plan your Shawarma Station',
    'cta_copy' => 'Tell us your date, guest count and venue, and VNV Events will prepare a written proposal with your selections and final total.',
];

$page['schema'] = [
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'Live Shawarma Station Catering',
    'url' => 'https://vnvevents.com/'.$page['slug'].'/',
    'image' => 'https://vnvevents.com/assets/images/services/shawarma-station.webp',
    'provider' => ['@type' => 'LocalBusiness', 'name' => 'VNV Events', 'telephone' => '+1-305-204-5427'],
    'areaServed' => ['Miami-Dade County', 'Broward County', 'Palm Beach County'],
    'offers' => [
        '@type' => 'Offer',
        'price' => '19.00',
        'priceCurrency' => 'USD',
        'url' => $page['product_url'],
        'description' => 'Per guest with a 25-guest minimum; final proposal confirms delivery, taxes, venue requirements and total.',
    ],
];

$router = new Router();
$router->get(fn () => TemplateResponse::render(
    dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'cateringServiceLanding.twig',
    ['page' => \App\Services\CateringLandingProductService::hydrate($page)]
));

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
