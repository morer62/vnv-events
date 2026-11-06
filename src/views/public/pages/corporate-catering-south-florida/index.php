<?php

use App\Services\CateringLandingProductService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'slug' => 'corporate-catering-south-florida',
    'meta_title' => 'Corporate Food Stations & Meal Boxes South Florida | VNV Events',
    'meta_keywords' => 'corporate catering South Florida, office catering Miami, boxed lunches Broward, employee appreciation catering, corporate food stations',
    'eyebrow' => 'Corporate Catering',
    'h1' => 'Corporate food stations for teams, meetings and company events',
    'hero_copy' => 'Choose Pasta, Appetizers, Crepes or Taco for a polished staffed experience. Corporate station pricing begins at 50 guests.',
    'hero_image' => 'assets/images/service_photos/pasta_station_2_lite.webp',
    'secondary_image' => 'assets/images/service_photos/brunch_tation_lite.webp',
    'image_alt' => 'Professional corporate catering setup by VNV Events',
    'section_title' => 'A focused corporate station program',
    'section_copy' => 'Build the main experience around Pasta or Taco, then use Appetizers or Crepes as complementary stations when the event needs another service moment.',
    'included' => [
        'Corporate station program for 50 or more guests',
        'Choice of Pasta, Appetizers, Crepes or Taco',
        'Professional station presentation',
        'Published inclusions from the selected Store service',
    ],
    'inclusion_note' => 'Final menu, service hours, equipment, delivery and dietary accommodations are confirmed in the proposal.',
    'best_for' => [
        'Recurring office breakfasts and lunches',
        'Employee appreciation programs',
        'Training days, seminars and conferences',
        'Product launches, mixers and company celebrations',
    ],
    'choices' => [
        ['title' => 'Pasta or Taco', 'copy' => 'Use one of these stations as the main corporate food experience.'],
        ['title' => 'Appetizers', 'copy' => 'Add a reception-style service moment before the main program.'],
        ['title' => 'Crepes', 'copy' => 'Add a dessert station for employee appreciation or company celebrations.'],
    ],
    'faqs' => [
        ['question' => 'How much are corporate meal boxes?', 'answer' => 'The current published meal-box price appears above when configured. Final pricing depends on menu, quantity, packaging and delivery.'],
        ['question' => 'How much is the corporate food-station package?', 'answer' => 'The current Store price and minimum appear above.'],
        ['question' => 'What if we have 25 to 49 guests?', 'answer' => 'Choose the regular Pasta, Appetizer, Crepes or Taco station program. Corporate station pricing begins at 50 guests.'],
        ['question' => 'Can breakfast and lunch boxes be customized?', 'answer' => 'Yes. Meal boxes are a separate format; final selections, quantities, packaging, delivery and dietary needs must be approved in advance.'],
    ],
    'cta_title' => 'Create a focused food program for your team',
    'cta_copy' => 'Send the headcount, schedule and preferred station, and VNV can build the appropriate company proposal.',
];

$router = new Router();
$router->get(function () use ($page) {
    $page['campaign_stations'] = CateringLandingProductService::catalog();
    return TemplateResponse::render(
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'cateringServiceLanding.twig',
        CateringLandingProductService::viewData($page)
    );
});
$router->run();
