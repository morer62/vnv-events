<?php

use App\Utils\LocationUtils;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    LocationUtils::redirectInternal('panel/home');
});

$router->run();
