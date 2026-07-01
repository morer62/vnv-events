<?php

use App\Utils\Router;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    LocationUtils::redirectInternal('panel/home');
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
