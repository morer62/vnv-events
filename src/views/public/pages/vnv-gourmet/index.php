<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\CateringLandingProductService;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'gourmet_catalog' => CateringLandingProductService::catalog(),
        'seo' => [
            'title' => 'VNV Gourmet | Luxury Event Catering in South Florida',
            'description' => 'Premium event catering in South Florida with chef-led stations, appetizers, desserts and full VNV Gourmet service.',
            'canonical' => 'https://vnvevents.com/vnv-gourmet/',
            'og_type' => 'website',
            'og_image' => 'https://vnvevents.com/assets/images/vnv-gourmet-hero-small-fast.webp',
            'og_image_alt' => 'VNV Gourmet event catering in South Florida',
        ],
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
