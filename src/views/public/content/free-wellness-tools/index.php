<?php

use App\Repositories\StoreProductsAudiencesRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $audiencesRepo = new StoreProductsAudiencesRepository();

    $professionalProducts = $audiencesRepo->getProductsByAudience('professional') ?: [];
    $healthyProducts      = $audiencesRepo->getProductsByAudience('healthy_active') ?: [];
    $familyProducts       = $audiencesRepo->getProductsByAudience('family_kids') ?: [];
    $corporateProducts    = $audiencesRepo->getProductsByAudience('corporate') ?: [];

    $audienceBlocks = [
        'professional' => [
            'key' => 'professional',
            'title' => 'Busy Professionals',
            'description' => 'Reliable, balanced meals for people with demanding schedules.',
            'count' => count($professionalProducts),
            'products' => array_slice($professionalProducts, 0, 4),
        ],
        'healthy_active' => [
            'key' => 'healthy_active',
            'title' => 'Healthy & Active',
            'description' => 'Protein-forward and cleaner meal options for more structure and consistency.',
            'count' => count($healthyProducts),
            'products' => array_slice($healthyProducts, 0, 4),
        ],
        'family_kids' => [
            'key' => 'family_kids',
            'title' => 'Family & Kids',
            'description' => 'Practical and family-friendly meals for busy weeks at home.',
            'count' => count($familyProducts),
            'products' => array_slice($familyProducts, 0, 4),
        ],
        'corporate' => [
            'key' => 'corporate',
            'title' => 'Corporate Meals',
            'description' => 'Meal solutions for offices, teams, and recurring workplace food service.',
            'count' => count($corporateProducts),
            'products' => array_slice($corporateProducts, 0, 4),
        ],
    ];

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "audienceBlocks" => $audienceBlocks
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}