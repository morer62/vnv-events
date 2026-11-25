<?php

use App\Services\BreakClubPanel;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$router->get(static function () {
    BreakClubPanel::requireUser(1);
    return TemplateResponse::render(__DIR__ . '/index.twig');
});
$router->run();
