<?php

use App\Utils\Router;
use App\Utils\LocationUtils;
use App\Services\LoginService;

$router = new Router();

$router->get(function () {
    LoginService::getSession();
    LocationUtils::redirectInternal('panel/home');
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
