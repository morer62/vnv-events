<?php

use App\Utils\JsonResponse;
use App\Utils\Cors;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    return JsonResponse::createResponse([
        "version" => "2.0.0" // <- cambia esto cuando quieras forzar actualización
    ]);
});

$router->run();
