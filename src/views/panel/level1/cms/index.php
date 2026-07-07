<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\SiteContext;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $cmsCategoriesRepository = new CmsCategoriesRepository();
    $cmsCategoriesRepository->db = $db;

    $contentItems = $contentsRepository->getAllForPanel('en', SiteContext::siteKey());
    $typeCounts = [
        'page' => 0,
        'location' => 0,
        'blog' => 0,
    ];
    $publishedCount = 0;
    $draftCount = 0;

    foreach ($contentItems as $item) {
        $contentType = normalizeCmsDashboardContentType((string)($item->content_type ?? $item->type ?? 'page'));
        $typeCounts[$contentType]++;

        $status = strtoupper((string)($item->status ?? ''));
        if ($status === 'PUBLISHED') {
            $publishedCount++;
        }
        if ($status === 'DRAFT') {
            $draftCount++;
        }
    }

    $totalCmsCategories = count($cmsCategoriesRepository->getAllForPanel());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"               => "CMS",
        "siteKey"             => SiteContext::siteKey(),
        "originLabel"         => "ophyra_growth_hub",
        "totalPages"          => $typeCounts['page'],
        "totalLocations"      => $typeCounts['location'],
        "totalBlogArticles"   => $typeCounts['blog'],
        "totalContent"        => count($contentItems),
        "totalCmsCategories"  => $totalCmsCategories,
        "totalCategories"     => $totalCmsCategories,
        "totalTemplates"      => count($templatesRepository->getAllForPanel()),
        "totalPublishedPages" => $publishedCount,
        "totalDraftPages"     => $draftCount,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

function normalizeCmsDashboardContentType(string $contentType): string
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
