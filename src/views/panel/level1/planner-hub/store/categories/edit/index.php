<?php

use App\Repositories\StoreCategoriesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $repo = new StoreCategoriesRepository();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid category.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    $category = $repo->getOne(['id' => $id]);

    if (!$category) {
        MessageUtil::setMessage("Category not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "category" => $category
    ]);
});

$router->post(function () {

    $repo = new StoreCategoriesRepository();

    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $status = trim($_POST['status'] ?? 'ACTIVE');

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid category.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    if ($name === '') {
        MessageUtil::setMessage("Category name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/edit?id=" . $id);
    }

    $slug = $repo->generateUniqueSlug($name);

    $repo->update([
        'name' => $name,
        'slug' => $slug,
        'description' => $description ?: null,
        'icon' => $icon ?: null,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $id
    ]);

    MessageUtil::setMessage("Category updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
});

$router->run();