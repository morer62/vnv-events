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

    $totalPages = $contentsRepository->countByType('page');
    $totalCmsCategories = count($cmsCategoriesRepository->getAllForPanel());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"               => "CMS",
        "siteKey"             => SiteContext::siteKey(),
        "originLabel"         => "ophyra_growth_hub",
        "totalPages"          => $totalPages,
        "totalContent"        => $totalPages,
        "totalCmsCategories"  => $totalCmsCategories,
        "totalCategories"     => $totalCmsCategories,
        "totalTemplates"      => count($templatesRepository->getAllForPanel()),
        "totalPublishedPages" => $contentsRepository->countByTypeAndStatus('page', 'PUBLISHED'),
        "totalDraftPages"     => $contentsRepository->countByTypeAndStatus('page', 'DRAFT'),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
