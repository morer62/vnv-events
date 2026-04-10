<?php

use App\Repositories\StoreAttributesRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new StoreAttributesRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "attributes" => $repo->getAll()
    ]);
});

$router->run();