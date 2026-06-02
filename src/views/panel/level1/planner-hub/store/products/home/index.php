<?php

use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new StoreProductsRepository();
    $ownerId = AvomealContext::ownerId();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "products" => $repo->getAllByOwner($ownerId)
    ]);
});

$router->run();
