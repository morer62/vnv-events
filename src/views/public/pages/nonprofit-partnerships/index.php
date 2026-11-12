<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (empty($_SESSION['partnership_csrf'])) {
        $_SESSION['partnership_csrf'] = bin2hex(random_bytes(24));
    }

    $seo = [
        'title' => 'Nonprofit Event Partnerships in South Florida | VNV Events',
        'description' => 'Apply for VNV Events nonprofit event support in South Florida, including concept development, logistics, event technology, catering, staffing, decor and sponsor guidance.',
        'canonical' => 'https://vnvevents.com/nonprofit-partnerships/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'seo' => $seo,
        'partnership_csrf' => $_SESSION['partnership_csrf'],
        'submitted' => isset($_GET['submitted']),
        'schemaJson' => [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Service',
                    'name' => 'VNV Nonprofit Event Partnerships',
                    'description' => $seo['description'],
                    'url' => $seo['canonical'],
                    'provider' => ['@type' => 'Organization', 'name' => 'VNV Events', 'url' => 'https://vnvevents.com/'],
                    'areaServed' => [
                        ['@type' => 'AdministrativeArea', 'name' => 'Broward County'],
                        ['@type' => 'AdministrativeArea', 'name' => 'Miami-Dade County'],
                        ['@type' => 'AdministrativeArea', 'name' => 'Palm Beach County'],
                    ],
                ],
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => [
                        ['@type' => 'Question', 'name' => 'Does an application guarantee approval or donated services?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'No. Submission starts a review only. Any support must be approved in writing with a defined scope.']],
                        ['@type' => 'Question', 'name' => 'How do the preferential nonprofit terms work?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Approved projects may qualify for up to 60% preferential pricing on selected food or staffing and up to 80% on selected decor, depending on reference pricing, capacity and approved scope.']],
                        ['@type' => 'Question', 'name' => 'Will VNV guarantee sponsors or fundraising revenue?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'No. VNV may provide guidance and consented introductions but cannot promise meetings, sponsors, funding, revenue or return on investment.']],
                    ],
                ],
            ],
        ],
    ]);
});

$router->run();
