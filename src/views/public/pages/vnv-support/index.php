<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$router->get(function () {
    if (empty($_SESSION['quick_request_csrf'])) {
        $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    }

    $seo = [
        'title' => 'VNV Support | Cuebook Event Operations for Companies and Nonprofits',
        'description' => 'See how VNV producers use Cuebook to coordinate crews, guest check-in and sponsor commitments. Explore access through corporate production or nonprofit support.',
        'canonical' => 'https://vnvevents.com/vnv-support/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'seo' => $seo,
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
        'schemaJson' => [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => 'VNV Support — Cuebook',
            'description' => $seo['description'],
            'url' => $seo['canonical'],
            'provider' => ['@type' => 'Organization', 'name' => 'VNV Events', 'url' => 'https://vnvevents.com/'],
        ],
    ]);
});
$router->run();
