<?php

use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $templates = $templatesRepository->getAll();

    usort($templates, function ($a, $b) {
        return strcmp($a->name ?? '', $b->name ?? '');
    });

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"     => "CMS Templates",
        "templates" => $templates,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}