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
        "title" => "Location Pages",
        "pages" => $repo->getAllForPanel()
    ]);
});

$router->post(function () {
    $repo = new LocationPagesRepository();

    $action = trim($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid page ID.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    $page = $repo->getOne([
        'id' => $id
    ]);

    if (!$page) {
        MessageUtil::setMessage("Location page not found.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'delete') {
        $repo->delete([
            'id' => $id
        ]);

        MessageUtil::setMessage("Location page deleted successfully.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'publish') {
        $repo->update([
            'status' => 'PUBLISHED',
            'published_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $id
        ]);

        MessageUtil::setMessage("Location page published successfully.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'draft') {
        $repo->update([
            'status' => 'DRAFT',
            'published_at' => null
        ], [
            'id' => $id
        ]);

        MessageUtil::setMessage("Location page moved to draft.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    MessageUtil::setMessage("Invalid action.");
    LocationUtils::redirectInternal("panel/cms/location-pages");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}