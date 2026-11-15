<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $programs = require dirname(__DIR__, 5) . '/config/podcasts.php';

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'programs' => $programs,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
