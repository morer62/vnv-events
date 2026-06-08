<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $contentTypes = ['page', 'location', 'blog'];

    $filters = [
        'status' => strtoupper(trim((string)($_GET['status'] ?? ''))),
        'content_type' => strtolower(trim((string)($_GET['content_type'] ?? ''))),
        'q' => trim((string)($_GET['q'] ?? '')),
    ];

    $pages = $contentsRepository->getAllByType('page', 'en', SiteContext::siteKey());

    $pages = array_values(array_filter($pages, static function ($page) use ($filters): bool {
        if ($filters['status'] !== '' && strtoupper((string)($page->status ?? '')) !== $filters['status']) {
            return false;
        }

        $contentType = normalizeCmsContentType((string)($page->content_type ?? 'page'));

        if ($filters['content_type'] !== '' && $contentType !== $filters['content_type']) {
            return false;
        }

        if ($filters['q'] !== '') {
            $haystack = strtolower(implode(' ', [
                (string)($page->title ?? ''),
                (string)($page->slug ?? ''),
                (string)($page->meta_title ?? ''),
                (string)($page->meta_description ?? ''),
                $contentType,
            ]));

            return str_contains($haystack, strtolower($filters['q']));
        }

        return true;
    }));

    foreach ($pages as $page) {
        $page->content_type = normalizeCmsContentType((string)($page->content_type ?? 'page'));
        $page->main_route = $routesRepository->getMainRouteByContent((int)$page->id, $page->language ?? 'en');
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "CMS Content",
        "pages" => $pages,
        "contentTypes" => $contentTypes,
        "filters" => $filters,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

function normalizeCmsContentType(string $contentType): string
{
    $contentType = strtolower(trim($contentType));

    if ($contentType === 'location') {
        return 'location';
    }

    if (in_array($contentType, ['blog', 'guide', 'faq_page', 'comparison', 'case_study'], true)) {
        return 'blog';
    }

    return 'page';
}
