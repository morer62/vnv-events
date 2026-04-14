<?php

use App\Repositories\LocationPagesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new LocationPagesRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "pages" => $repo->getAllForPanel()
    ]);
});

$router->post(function () {
    $repo = new LocationPagesRepository();

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (!$id) {
        MessageUtil::setMessage("Invalid page ID.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages");
    }

    $page = $repo->getOne(['id' => $id]);

    if (!$page) {
        MessageUtil::setMessage("Location page not found.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages");
    }

    if ($action === 'delete') {
        $repo->delete(['id' => $id]);
        MessageUtil::setMessage("Location page deleted successfully.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages");
    }

    if ($action === 'publish') {
        $repo->update([
            'status' => 'PUBLISHED',
            'published_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);

        MessageUtil::setMessage("Location page published successfully.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages");
    }

    if ($action === 'draft') {
        $repo->update([
            'status' => 'DRAFT'
        ], ['id' => $id]);

        MessageUtil::setMessage("Location page moved to draft.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages");
    }

    MessageUtil::setMessage("Invalid action.");
    LocationUtils::redirectInternal("panel/level6/cms/location-pages");
});

$router->run();