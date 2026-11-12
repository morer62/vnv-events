<?php

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
        'title' => 'Sponsor Activation Production in South Florida | VNV Events',
        'description' => 'VNV Events produces sponsor lounges, hospitality, sampling, photo moments and branded installations inside South Florida events with documented approvals and deliverables.',
        'canonical' => 'https://vnvevents.com/sponsor-activation-production/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'seo' => $seo,
        'partnership_csrf' => $_SESSION['partnership_csrf'],
        'submitted' => isset($_GET['submitted']),
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
        'schemaJson' => [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => 'Sponsor Activation Production in South Florida',
            'description' => $seo['description'],
            'url' => $seo['canonical'],
            'provider' => ['@type' => 'Organization', 'name' => 'VNV Events', 'url' => 'https://vnvevents.com/'],
            'areaServed' => ['Miami-Dade County', 'Broward County', 'Palm Beach County'],
        ],
    ]);
});

$router->run();
