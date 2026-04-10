<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;

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
