<?php

use App\Repositories\CmsTemplatesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new CmsTemplatesRepository();
    $id = (int)($_GET['id'] ?? 0);

    $template = $repo->getOne(['id' => $id]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "template" => $template
    ]);
});

$router->post(function () {
    $repo = new CmsTemplatesRepository();
    $id = (int)($_POST['id'] ?? 0);

    $repo->update([
        'name' => $_POST['name'],
        'template_key' => $_POST['template_key'],
        'type' => $_POST['type'],
        'preview_html' => $_POST['preview_html'],
        'template_structure_json' => $_POST['template_structure_json']
    ], ['id' => $id]);

    LocationUtils::redirectInternal("panel/cms/templates");
});

$router->run();