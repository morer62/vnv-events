<?php

use App\Services\CateringLandingProductService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'slug' => 'corporate-catering-south-florida',
    'meta_title' => 'Corporate Catering & Meal Boxes South Florida | VNV Events',
    'meta_keywords' => 'corporate catering South Florida, office catering Miami, boxed lunches Broward, employee appreciation catering, corporate breakfast boxes',
    'eyebrow' => 'Corporate Catering',
    'h1' => 'Corporate catering built for teams, meetings and appreciation events',
    'hero_copy' => 'Choose a polished staffed station for a company experience or individually packed breakfast and lunch boxes designed for efficient distribution.',
    'hero_image' => 'assets/images/service_photos/pasta_station_2_lite.webp',
    'secondary_image' => 'assets/images/service_photos/brunch_tation_lite.webp',
    'image_alt' => 'Professional corporate catering setup by VNV Events',
    'section_title' => 'Two practical ways to feed a company',
    'section_copy' => 'Use a live food station when the meal should feel like an event, or choose customizable meal boxes when speed, distribution and individual portions matter most.',
    'included' => [
        'Food-station program for 50 or more guests',
        'Choice of pasta, paella, pizza, brunch or Mexican station',
        'Professional buffet presentation and décor',
        'One hour of on-site chef service with the station package',
        'Customizable breakfast and lunch meal boxes',
        'Optional warming support',
    ],
    'inclusion_note' => 'Meal-box menus, minimum quantities, delivery, equipment and dietary accommodations must be confirmed in the proposal.',
    'best_for' => [
        'Recurring office breakfasts and lunches',
        'Employee appreciation programs',
        'Training days, seminars and conferences',
        'Product launches, mixers and company celebrations',
    ],
    'choices' => [
        ['title' => 'Meal boxes', 'copy' => 'Customizable breakfast or lunch options packed for quick, organized distribution.'],
        ['title' => 'Food stations', 'copy' => 'Choose one curated live station for a professional company gathering of 50 or more guests.'],
        ['title' => 'Warming support', 'copy' => 'Add the recommended warming station when the selected menu benefits from heated holding and service.'],
    ],
    'faqs' => [
        ['question' => 'How much are corporate meal boxes?', 'answer' => 'The current published meal-box price appears above. Final pricing depends on menu, quantity, packaging and delivery.'],
        ['question' => 'How much is the corporate food-station package?', 'answer' => 'The current Store price and minimum appear above.'],
        ['question' => 'What is the optional warming station?', 'answer' => 'It is heated-holding support recommended for menus that need to remain warm during distribution or service.'],
        ['question' => 'Can breakfast and lunch menus be customized?', 'answer' => 'Yes. The meal-box program is intended to be customizable; final selections and dietary needs must be approved in advance.'],
    ],
    'cta_title' => 'Create a repeatable food program for your team',
    'cta_copy' => 'Send the headcount, schedule and service format, and VNV can build the appropriate company proposal.',
];

$router = new Router();
$router->get(fn() => TemplateResponse::render(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'cateringServiceLanding.twig', CateringLandingProductService::viewData($page)));
$router->run();
