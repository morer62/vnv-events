<?php

use App\Services\CateringLandingProductService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$page = [
    'service_key' => 'appetizer',
    'slug' => 'appetizer-catering-south-florida',
    'meta_title' => 'Appetizer Catering South Florida | 7 Pieces Per Guest | VNV',
    'meta_keywords' => 'appetizer catering South Florida, hors d oeuvres Miami, cocktail hour catering Broward, appetizer station Palm Beach',
    'eyebrow' => 'Appetizer Catering',
    'h1' => 'Seven polished bites per guest, ready for cocktail hour',
    'hero_copy' => 'Choose a classic appetizer collection or combine sushi and savory bites in a guest-friendly presentation for social and corporate events.',
    'hero_image' => 'assets/images/service_photos/appetizers_lite.webp',
    'secondary_image' => 'assets/images/service_photos/sushi_lite.webp',
    'image_alt' => 'VNV Events appetizer catering presentation in South Florida',
    'section_title' => 'A complete appetizer format with two clear collection choices',
    'section_copy' => 'The published package includes seven appetizer pieces per guest. Choose the collection that fits the menu direction and guest experience.',
    'included' => [
        'Seven appetizer pieces per guest',
        'Signature Collection: seven appetizers selected from the published menu',
        'Ocean Blue Collection: four sushi pieces in a boat and three appetizers',
        'Selection and final presentation confirmed with the event proposal',
    ],
    'inclusion_note' => 'The current product menu is the source of truth for available appetizer and sushi selections.',
    'best_for' => [
        'Cocktail hours and welcome receptions',
        'Corporate gatherings and holiday events',
        'Weddings, showers and milestone celebrations',
        'Hosts pairing appetizers with Pasta, Crepes or Taco Station',
    ],
    'choices' => [
        ['title' => 'Signature Collection', 'copy' => 'Choose seven appetizers from the current published menu.'],
        ['title' => 'Ocean Blue Collection', 'copy' => 'Serve four sushi pieces in a boat plus three appetizer selections.'],
        ['title' => 'Pair another station', 'copy' => 'Add Pasta, Crepes or Taco Station through a custom event proposal; no combo price is assumed.'],
    ],
    'faqs' => [
        ['question' => 'How many appetizers are included per guest?', 'answer' => 'The published base package includes seven appetizer pieces per guest.'],
        ['question' => 'What is the Ocean Blue Collection?', 'answer' => 'It combines four sushi pieces served in a boat with three appetizer pieces per guest.'],
        ['question' => 'Can I add an extra bite?', 'answer' => 'The product offers an additional bite as a separately priced add-on. It does not change the base From price.'],
    ],
    'cta_title' => 'Start with seven bites and build the right event menu',
    'cta_copy' => 'Share the date, guest range and ZIP to check availability for your appetizer collection.',
];

$router = new Router();
$router->get(fn() => TemplateResponse::render(
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'cateringServiceLanding.twig',
    CateringLandingProductService::viewData($page)
));
$router->run();
