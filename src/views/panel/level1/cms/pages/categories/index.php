<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $categories = $categoriesRepository->getAllForPanel();

    usort($categories, function ($a, $b) {
        return strcmp($a->name ?? '', $b->name ?? '');
    });

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"      => "CMS Categories",
        "categories" => $categories,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
