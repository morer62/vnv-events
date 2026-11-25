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
        'title' => 'Cause on Cue 2027 | Nonprofit Impact Initiative',
        'description' => 'Cause on Cue 2027 offers South Florida nonprofits fundraiser production, a branded digital guest platform and optional donor or sponsor introductions.',
        'canonical' => 'https://vnvevents.com/nonprofit-partnerships/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'seo' => $seo,
        'partnership_csrf' => $_SESSION['partnership_csrf'],
        'submitted' => isset($_GET['submitted']),
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
        'schemaJson' => [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Service',
                    'name' => 'Cause on Cue 2027 — Nonprofit Impact Initiative',
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
                        ['@type' => 'Question', 'name' => 'Can we apply without a confirmed event?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. Tell us what you want your fundraiser to accomplish and what support you need. You can add the date and venue later.']],
                        ['@type' => 'Question', 'name' => 'Can VNV help us connect with donors or sponsors?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. For an approved project, we can help define sponsor opportunities and facilitate introductions when both parties agree. Your organization makes the fundraising decisions. Introductions do not guarantee sponsorship, donations or fundraising results.']],
                        ['@type' => 'Question', 'name' => 'What does the program cover?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'VNV Events provides professional and operational support within a written Approved Support Plan. The program does not guarantee approval, cash grants, funding, sponsors, fundraising results, a free event or catering, payment of venues, materials or vendors, unlimited staffing or software development, or ongoing technical support.']],
                        ['@type' => 'Question', 'name' => 'How will we review results?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'VNV and the organization agree on expected outcomes in the support plan and review the work after delivery. We report only results that can be verified.']],
                    ],
                ],
            ],
        ],
    ]);
});

$router->run();
