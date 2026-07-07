<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->post(function () {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action !== 'publish_generated') {
        LocationUtils::redirectInternal('panel/cms/pages');
    }

    $db = new Connection();
    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;

    $items = $contentsRepository->getAllForPanel('en', SiteContext::siteKey());
    $published = 0;
    foreach ($items as $item) {
        if (strtoupper((string)($item->status ?? '')) !== 'GENERATED') {
            continue;
        }

        $ok = $contentsRepository->update([
            'status' => 'PUBLISHED',
            'published_at' => date('Y-m-d H:i:s'),
            'updated_by' => $authorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int)$item->id]);

        if ($ok) {
            $published++;
        }
    }

    MessageUtil::setMessage($published . ' generated content item(s) published.');
    LocationUtils::redirectInternal('panel/cms/pages?status=PUBLISHED');
});

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $contentTypes = ['blog', 'location', 'page'];

    $filters = [
        'status' => strtoupper(trim((string)($_GET['status'] ?? ''))),
        'content_type' => strtolower(trim((string)($_GET['content_type'] ?? ''))),
        'q' => trim((string)($_GET['q'] ?? '')),
    ];

    if (!in_array($filters['content_type'], $contentTypes, true)) {
        $filters['content_type'] = 'blog';
    }

    $allPages = $contentsRepository->getAllForPanel('en', SiteContext::siteKey());
    $typeCounts = array_fill_keys($contentTypes, 0);
    foreach ($allPages as $page) {
        $typeCounts[normalizeCmsContentType((string)($page->content_type ?? $page->type ?? 'page'))]++;
    }

    $pages = array_values(array_filter($allPages, static function ($page) use ($filters): bool {
        if ($filters['status'] !== '' && strtoupper((string)($page->status ?? '')) !== $filters['status']) {
            return false;
        }

        $contentType = normalizeCmsContentType((string)($page->content_type ?? $page->type ?? 'page'));

        if ($contentType !== $filters['content_type']) {
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

    $generatedCount = count(array_filter($pages, static fn ($page): bool => strtoupper((string)($page->status ?? '')) === 'GENERATED'));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "CMS Content",
        "pages" => $pages,
        "contentTypes" => $contentTypes,
        "typeCounts" => $typeCounts,
        "filters" => $filters,
        "generatedCount" => $generatedCount,
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

    if (in_array($contentType, ['blog', 'post', 'guide', 'faq_page', 'comparison', 'case_study'], true)) {
        return 'blog';
    }

    return 'page';
}
