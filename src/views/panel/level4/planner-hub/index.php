<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
