<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    if (($_GET['module'] ?? '') === 'orders') {
        LocationUtils::redirectInternal('panel/planner-hub/team/orders/orders/');
    }

    return TemplateResponse::render(__DIR__ . "/index.twig");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
