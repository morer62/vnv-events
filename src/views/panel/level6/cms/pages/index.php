<?php

use App\Repositories\CmsPagesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $pagesRepository = new CmsPagesRepository();
    $pagesRepository->db = $db;

    $pages = $pagesRepository->getAllWithCategoryAndTemplate();

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