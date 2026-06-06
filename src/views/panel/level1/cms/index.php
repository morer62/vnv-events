<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsCategoriesRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Repositories\LocationPagesRepository;
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

    $blogCategoriesRepository = new BlogCategoriesRepository();
    $blogCategoriesRepository->db = $db;

    $cmsCategoriesRepository = new CmsCategoriesRepository();
    $cmsCategoriesRepository->db = $db;

    $locationPagesRepository = new LocationPagesRepository();
    $locationPagesRepository->db = $db;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"               => "CMS",
        "siteKey"             => SiteContext::siteKey(),
        "originLabel"         => "vnv_events",
        "totalPages"          => $contentsRepository->countByType('page'),
        "totalPosts"          => $contentsRepository->countByType('post'),
        "totalCmsCategories"  => count($cmsCategoriesRepository->getAllForPanel()),
        "totalBlogCategories" => count($blogCategoriesRepository->getAllForPanel()),
        "totalLocationPages"  => count($locationPagesRepository->getAllForPanel()),
        "totalTemplates"      => count($templatesRepository->getAll()),
        "totalPublishedPages" => $contentsRepository->countByTypeAndStatus('page', 'PUBLISHED'),
        "totalPublishedPosts" => $contentsRepository->countByTypeAndStatus('post', 'PUBLISHED'),
        "totalDraftPages"     => $contentsRepository->countByTypeAndStatus('page', 'DRAFT'),
        "totalDraftPosts"     => $contentsRepository->countByTypeAndStatus('post', 'DRAFT'),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
