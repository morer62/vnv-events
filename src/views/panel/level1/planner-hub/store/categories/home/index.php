<?php

use App\Repositories\StoreCategoriesRepository;
use App\Utils\AvomealContext;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new StoreCategoriesRepository();
    $ownerId = AvomealContext::ownerId();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $repo->getActive($ownerId)
    ]);
});

$router->run();
