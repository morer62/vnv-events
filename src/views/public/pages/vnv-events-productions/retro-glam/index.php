<?php

use App\Services\RetroGlamEditions;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $attribution = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid'] as $key) {
        $value = $_GET[$key] ?? null;
        if (is_string($value) && $value !== '' && strlen($value) <= 512) {
            $attribution[$key] = $value;
        }
    }
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'retro_edition_titles' => RetroGlamEditions::TITLES,
        'edition_attribution' => $attribution,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
