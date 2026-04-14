<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $categoriesRepository = new BlogCategoriesRepository();
    $categoriesRepository->db = $db;

    $categories = $categoriesRepository->getAllForPanel();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Blog Categories",
        "categories" => $categories,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}