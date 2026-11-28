<?php

use App\Services\VnvProductionCatalog;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (empty($_SESSION['partnership_csrf'])) {
        $_SESSION['partnership_csrf'] = bin2hex(random_bytes(24));
    }
    if (empty($_SESSION['quick_request_csrf'])) {
        $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    }

    $seo = [
        'title' => 'Brand Experiences & Activation in South Florida | VNV Events',
        'description' => 'Compare fixed fees for standalone brand activation, explore sponsorship opportunities or include an activation in your South Florida event production.',
        'canonical' => 'https://vnvevents.com/sponsor-activation-production/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'production_services' => VnvProductionCatalog::services(),
        'fee_includes' => VnvProductionCatalog::FEE_INCLUDES,
        'direct_costs' => VnvProductionCatalog::DIRECT_COSTS,
        'seo' => $seo,
        'partnership_csrf' => $_SESSION['partnership_csrf'],
        'submitted' => isset($_GET['submitted']),
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
        'schemaJson' => [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => 'Brand Experiences & Activation in South Florida',
            'description' => $seo['description'],
            'url' => $seo['canonical'],
            'provider' => ['@type' => 'Organization', 'name' => 'VNV Events', 'url' => 'https://vnvevents.com/'],
            'areaServed' => ['Miami-Dade County', 'Broward County', 'Palm Beach County'],
        ],
    ]);
});

$router->run();
