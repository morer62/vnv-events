<?php

use App\Repositories\CmsTemplatesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new CmsTemplatesRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Templates",
        "templates" => $repo->getAll()
    ]);
});

$router->post(function () {
    $repo = new CmsTemplatesRepository();

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (!$id) {
        MessageUtil::setMessage("Invalid template ID.");
        LocationUtils::redirectInternal("panel/cms/templates");
    }

    if ($action === 'delete') {
        $repo->delete(['id' => $id]);
        MessageUtil::setMessage("Template deleted.");
    }

    if ($action === 'activate') {
        $repo->update(['status' => 'ACTIVE'], ['id' => $id]);
        MessageUtil::setMessage("Template activated.");
    }

    if ($action === 'deactivate') {
        $repo->update(['status' => 'INACTIVE'], ['id' => $id]);
        MessageUtil::setMessage("Template deactivated.");
    }

    LocationUtils::redirectInternal("panel/cms/templates");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}