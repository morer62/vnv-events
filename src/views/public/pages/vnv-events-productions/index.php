<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (empty($_SESSION['partnership_csrf'])) {
        $_SESSION['partnership_csrf'] = bin2hex(random_bytes(24));
    }
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'partnership_csrf' => $_SESSION['partnership_csrf'],
        'submitted' => isset($_GET['submitted']),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
