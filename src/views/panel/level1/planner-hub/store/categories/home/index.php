<?php

use App\Repositories\StoreCategoriesRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new StoreCategoriesRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $repo->getActive()
    ]);
});

$router->run();