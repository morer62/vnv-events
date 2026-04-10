<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\CmsPagesRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $pagesRepository = new CmsPagesRepository();
    $pagesRepository->db = $db;

    $totalCategories = count($categoriesRepository->getAll());
    $totalTemplates  = count($templatesRepository->getAll());
    $totalPages      = count($pagesRepository->getAll());
    $totalPublished  = count($pagesRepository->getPublished());
    $totalDrafts     = count($pagesRepository->getDrafts());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"            => "CMS",
        "totalCategories"  => $totalCategories,
        "totalTemplates"   => $totalTemplates,
        "totalPages"       => $totalPages,
        "totalPublished"   => $totalPublished,
        "totalDrafts"      => $totalDrafts,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}