<?php

use App\Services\CateringLandingProductService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'slug' => 'pasta-station-catering-south-florida',
    'meta_keywords' => 'pasta station catering South Florida, live pasta station Miami, wedding pasta bar, corporate pasta catering Broward',
    'eyebrow' => 'Live Pasta Station',
    'h1' => 'A custom pasta experience prepared live for every guest',
    'hero_copy' => 'Comforting, flexible and polished: guests combine pasta, sauce, protein and toppings while live chefs keep service moving.',
    'hero_image' => 'assets/images/service_photos/pasta_station_1.webp',
    'secondary_image' => 'assets/images/service_photos/pasta_station_2_lite.webp',
    'image_alt' => 'Live pasta station catering by VNV Events in South Florida',
    'section_title' => 'The flexibility of a pasta bar with event-ready service',
    'section_copy' => 'A live pasta station works across age groups and event styles because the selections can move from classic and creamy to lighter, spicy or vegetarian.',
    'included' => [
        'Choose three pasta varieties',
        'Choose three classic or specialty sauces',
        'Two protein selections',
        'Fresh ingredients and finishing toppings',
        'Buffet setup, decoration and two hours of live-chef service',
    ],
    'inclusion_note' => 'Pasta, sauce and protein selections are finalized with the event proposal.',
    'best_for' => [
        'Weddings and rehearsal dinners',
        'Corporate appreciation events',
        'Birthdays, anniversaries and family celebrations',
        'Mixed groups needing broad menu flexibility',
    ],
    'choices' => [
        ['title' => 'Pasta selection', 'copy' => 'Choose familiar shapes or filled pasta based on the menu direction.'],
        ['title' => 'Sauce selection', 'copy' => 'Balance marinara, Alfredo, pesto or another available sauce for variety.'],
        ['title' => 'Protein and dietary needs', 'copy' => 'Select proteins and discuss vegetarian, vegan or allergy-related requirements in advance.'],
    ],
    'faqs' => [
        ['question' => 'What comes with the pasta station?', 'answer' => 'The published package includes three pastas, three sauces, fresh ingredients, two proteins, buffet setup and two hours of live chefs.'],
        ['question' => 'Can the menu include vegetarian choices?', 'answer' => 'Yes. Vegetarian and vegan directions can be discussed when the pasta, sauce and protein selections are confirmed.'],
        ['question' => 'Is staffing included?', 'answer' => 'Live-chef service is included in the published station package. Any additional servers or event staffing should be confirmed separately.'],
    ],
    'cta_title' => 'Build a pasta station around your guests',
    'cta_copy' => 'Share the guest count and event style, then choose the combinations that fit the room.',
];

$router = new Router();
$router->get(fn() => TemplateResponse::render(
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'cateringServiceLanding.twig',
    CateringLandingProductService::viewData($page)
));
$router->run();
