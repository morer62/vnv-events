<?php

use App\Services\CateringLandingProductService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'slug' => 'taco-station-catering-south-florida',
    'meta_keywords' => 'taco station catering South Florida, taco bar Miami, Mexican catering Broward, live taco chef event',
    'eyebrow' => 'Live Taco & Burrito Station',
    'h1' => 'A colorful taco station customized by every guest',
    'hero_copy' => 'Fresh on-site preparation, recognizable choices and fast guest flow make this one of the easiest stations to enjoy at social and corporate events.',
    'hero_image' => 'assets/images/service_photos/tacos.webp',
    'secondary_image' => 'assets/images/service_photos/tacos.webp',
    'image_alt' => 'Live taco and burrito catering station by VNV Events',
    'section_title' => 'Bold flavor without a complicated guest experience',
    'section_copy' => 'Guests build tacos or burritos from a focused set of proteins, sides and toppings while the chefs handle fresh preparation.',
    'included' => [
        'Taco and burrito bar',
        'Choose two proteins from carnitas, chicken or steak',
        'Choose three sides or toppings from the published list',
        'Two hours of live-chef interaction',
        'Fresh preparation on-site',
    ],
    'inclusion_note' => 'Available sides include rice, beans, cheese, guacamole, onion, cilantro and pico de gallo; final selections are confirmed before service.',
    'best_for' => [
        'Office lunches and team celebrations',
        'Birthdays and backyard events',
        'School and community gatherings',
        'Relaxed weddings and late-night food service',
    ],
    'choices' => [
        ['title' => 'Choose two proteins', 'copy' => 'Select carnitas, chicken or steak to give guests a focused but satisfying choice.'],
        ['title' => 'Choose three accompaniments', 'copy' => 'Build the service around rice, beans, cheese, guacamole or fresh toppings.'],
        ['title' => 'Plan the flow', 'copy' => 'Use tacos, burritos or both depending on guest count, timing and the atmosphere of the event.'],
    ],
    'faqs' => [
        ['question' => 'What proteins are available?', 'answer' => 'The current product offers carnitas, chicken and steak, with two protein selections included.'],
        ['question' => 'What sides can we choose?', 'answer' => 'The published list includes rice, beans, cheese, guacamole, onion, cilantro and pico de gallo, with three selections included.'],
        ['question' => 'Are chefs included?', 'answer' => 'Yes. The published package includes two hours of live chefs preparing customized dishes for guests.'],
    ],
    'cta_title' => 'Build a taco bar people will keep coming back to',
    'cta_copy' => 'Start with guest count and protein choices; VNV will help organize the rest.',
];

$router = new Router();
$router->get(fn() => TemplateResponse::render(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'cateringServiceLanding.twig', CateringLandingProductService::viewData($page)));
$router->run();
