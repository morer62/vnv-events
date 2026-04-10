<?php

use App\Repositories\StoreProductsRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new StoreProductsRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "products" => $repo->getAll()
    ]);
});

$router->run();