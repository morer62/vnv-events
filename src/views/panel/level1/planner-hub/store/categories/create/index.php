<?php

use App\Repositories\StoreCategoriesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", []);
});

$router->post(function () {
    $repo = new StoreCategoriesRepository();

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $status = trim($_POST['status'] ?? StoreCategoriesRepository::STATUS_ACTIVE);

    if ($name === '') {
        MessageUtil::setMessage("Category name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/create");
    }

    $slug = $repo->generateUniqueSlug($name);

    $ok = $repo->add([
        'name' => $name,
        'slug' => $slug,
        'description' => $description ?: null,
        'icon' => $icon ?: null,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    if (!$ok) {
        MessageUtil::setMessage("Category could not be created.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/create");
    }

    MessageUtil::setMessage("Category created successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
});

$router->run();