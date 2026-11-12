<?php

use App\Utils\Router;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    header('Location: ' . LocationUtils::pathFor('vnv-events-productions'), true, 301);
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
