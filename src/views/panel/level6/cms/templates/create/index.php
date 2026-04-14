<?php

use App\Repositories\CmsTemplatesRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig");
});

$router->post(function () {
    $repo = new CmsTemplatesRepository();
    $user = LoginService::getSession();

    $name = trim($_POST['name'] ?? '');
    $key = trim($_POST['template_key'] ?? '');
    $type = trim($_POST['type'] ?? 'page');
    $structure = $_POST['template_structure_json'] ?? '';
    $preview = $_POST['preview_html'] ?? '';

    if (!$name || !$key) {
        MessageUtil::setMessage("Name and key required.");
        LocationUtils::redirectInternal("panel/cms/templates/create");
    }

    if ($repo->templateKeyExists($key)) {
        MessageUtil::setMessage("Template key exists.");
        LocationUtils::redirectInternal("panel/cms/templates/create");
    }

    $repo->add([
        'id_owner' => $user ? $user->getOwner() : null,
        'name' => $name,
        'template_key' => $key,
        'type' => $type,
        'preview_html' => $preview,
        'template_structure_json' => $structure,
        'status' => 'ACTIVE'
    ]);

    LocationUtils::redirectInternal("panel/cms/templates");
});

$router->run();