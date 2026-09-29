<?php

use App\Utils\Router;
use App\Utils\LocationUtils;
use App\Services\LoginService;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    LocationUtils::redirectInternal(
        (int) $user->getLevel() === 1
            ? 'panel/planner-hub/management/orders/orders/execution'
            : 'panel/home'
    );
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
