<?php

use App\Services\VnvProductionCatalog;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$router->get(function () {
    if (empty($_SESSION['quick_request_csrf'])) {
        $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    }

    $seo = [
        'title' => 'VNV-Q Event Management Software by VNV Events',
        'description' => 'See how VNV producers use VNV-Q to coordinate crews, guest check-in and sponsor commitments. Explore access through corporate production or nonprofit support.',
        'canonical' => 'https://vnvevents.com/vnv-support/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'production_phases' => VnvProductionCatalog::phases(),
        'seo' => $seo,
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
        'schemaJson' => [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => 'VNV-Q Event Management Software',
            'description' => $seo['description'],
            'url' => $seo['canonical'],
            'provider' => ['@type' => 'Organization', 'name' => 'VNV Events', 'url' => 'https://vnvevents.com/'],
        ],
    ]);
});
$router->run();
