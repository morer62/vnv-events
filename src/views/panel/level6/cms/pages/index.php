<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $pages = $contentsRepository->getAllByType('page', 'en');

    foreach ($pages as $page) {
        $page->main_route = $routesRepository->getMainRouteByContent((int)$page->id, $page->language ?? 'en');
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "CMS Pages",
        "pages" => $pages,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}