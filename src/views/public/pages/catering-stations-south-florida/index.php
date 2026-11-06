<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\CateringLandingProductService;

$router = new Router();

$router->get(function () {
    $catalog = CateringLandingProductService::catalog();
    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'gourmet_catalog' => $catalog,
        'ads_mode' => CateringLandingProductService::adsMode(),
        'corporate_overview' => CateringLandingProductService::corporateOverview(),
        'comparison_schema' => CateringLandingProductService::comparisonSchema($catalog),
        'seo' => [
            'title' => 'Live Catering Stations in South Florida | VNV Events',
            'description' => 'Compare VNV Events Pasta, Appetizer, Crepes and Taco catering stations using current Store prices and minimums.',
            'canonical' => 'https://vnvevents.com/catering-stations-south-florida/',
            'og_type' => 'website',
            'og_image' => 'https://vnvevents.com/assets/images/service_photos/pasta_station_1.webp',
            'og_image_alt' => 'VNV Gourmet catering stations in South Florida',
        ],
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
