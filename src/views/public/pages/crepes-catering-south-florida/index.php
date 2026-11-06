<?php

use App\Services\CateringLandingProductService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'slug' => 'crepes-catering-south-florida',
    'meta_title' => 'Live Crepe Station Catering in South Florida | VNV Events',
    'meta_keywords' => 'crepe station catering South Florida, live crepes Miami, crepe catering Broward, wedding crepe station',
    'eyebrow' => 'Live Crepe Station',
    'h1' => 'Live crepes made fresh in front of your guests',
    'hero_copy' => 'A polished sweet-and-savory station that gives guests dinner, dessert and live interaction in one memorable experience.',
    'hero_image' => 'assets/images/service_photos/crepes_station_1_lite.webp',
    'secondary_image' => 'assets/images/service_photos/crepes_station_2.webp',
    'image_alt' => 'VNV Events live crepe station with sweet and savory fillings',
    'section_title' => 'A station guests watch, customize and remember',
    'section_copy' => 'Crepes bring movement to the room without sacrificing presentation. Each guest can choose a direction while the chef prepares it fresh on-site.',
    'included' => [
        'Variety of sweet and savory crepes prepared on-site',
        'Choice of three sweet fillings, including Nutella, strawberries and whipped cream',
        'Choice of three savory fillings such as ham, cheese, spinach or chicken',
        'Buffet setup with décor coordinated to the event theme',
        'Two hours of live-chef interaction and preparation',
    ],
    'inclusion_note' => 'Final fillings, dietary accommodations, setup and logistics are confirmed before booking.',
    'best_for' => [
        'Weddings, showers and milestone birthdays',
        'Corporate socials and client events',
        'Hosts who want an interactive dessert or meal experience',
        'Indoor or covered outdoor celebrations',
    ],
    'choices' => [
        ['title' => 'Sweet direction', 'copy' => 'Build around fruit, chocolate and cream for a dessert-forward experience.'],
        ['title' => 'Savory direction', 'copy' => 'Choose satisfying fillings that let the station work as part of the meal.'],
        ['title' => 'Event styling', 'copy' => 'Coordinate the buffet presentation with the colors and atmosphere of the celebration.'],
    ],
    'faqs' => [
        ['question' => 'How much is the VNV crepe station?', 'answer' => 'The current published Store price and minimum appear above.'],
        ['question' => 'Are the crepes prepared at the event?', 'answer' => 'Yes. The package includes live chefs preparing and customizing crepes on-site for two hours.'],
        ['question' => 'Can we offer both sweet and savory crepes?', 'answer' => 'Yes. The published package includes selections for both sweet and savory fillings. Final choices are confirmed with VNV.'],
    ],
    'cta_title' => 'Bring a live crepe experience to your event',
    'cta_copy' => 'Tell us the date, location and guest count to begin checking availability.',
];

$router = new Router();
$router->get(fn() => TemplateResponse::render(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'cateringServiceLanding.twig', CateringLandingProductService::viewData($page)));
$router->run();
