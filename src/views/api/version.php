<?php

use App\Utils\JsonResponse;
use App\Utils\Cors;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    return JsonResponse::createResponse([
        "version" => "4.0.11" // Change this when a mobile app update must be required.
    ]);
});

$router->run();
