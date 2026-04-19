<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
