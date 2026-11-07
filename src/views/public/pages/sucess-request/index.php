<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'seo' => [
            'title' => 'Request Received | VNV Events',
            'description' => 'Your request has been received by the VNV Events team.',
            'canonical' => 'https://vnvevents.com/sucess-request/',
            'robots' => 'noindex, nofollow',
        ],
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
