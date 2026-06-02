<?php

use App\Repositories\StoreCategoriesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function normalizeBuilderPayload($raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }

    json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $raw;
}

$router->get(function () {

    $repo = new StoreCategoriesRepository();
    $ownerId = AvomealContext::ownerId();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid category.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    $category = $repo->getOne(['id' => $id, 'id_owner' => $ownerId]);

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
    $ownerId = AvomealContext::ownerId();

    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slugInput = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $status = trim($_POST['status'] ?? 'ACTIVE');
    $metaTitle = trim($_POST['meta_title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $pageBuilderJson = normalizeBuilderPayload($_POST['page_builder_json'] ?? '');

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid category.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    if ($name === '') {
        MessageUtil::setMessage("Category name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/edit?id=" . $id);
    }

    $slugBase = $slugInput !== '' ? $slugInput : $name;
    $slug = $repo->generateUniqueSlug($slugBase, $id);

    $repo->update([
        'id_owner' => $ownerId,
        'name' => $name,
        'slug' => $slug,
        'description' => $description ?: null,
        'icon' => $icon ?: null,
        'meta_title' => $metaTitle ?: null,
        'meta_description' => $metaDescription ?: null,
        'page_builder_json' => $pageBuilderJson,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $id,
        'id_owner' => $ownerId
    ]);

    MessageUtil::setMessage("Category updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
});

$router->run();
