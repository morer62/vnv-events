<?php

use App\Services\LoginService;
use App\Repositories\MusicSessionsCategoryRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $categoryRepo = new MusicSessionsCategoryRepository();

    $id = $_GET["id"] ?? null;
    if (!$id) {
        MessageUtil::setMessage("Invalid category ID.");
        LocationUtils::redirectInternal("panel/multimedia-sessions/categories");
    }

    $category = $categoryRepo->getOne(["id" => $id]);

    if (!$category) {
        MessageUtil::setMessage("Category not found.");
        LocationUtils::redirectInternal("panel/multimedia-sessions/categories");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "category" => $category
    ]);
});

$router->post(function () {
    $categoryRepo = new MusicSessionsCategoryRepository();

    $id = $_POST["id"] ?? null;
    $name = trim($_POST["name"] ?? '');
    $description = trim($_POST["description"] ?? '');

    if (!$id || empty($name)) {
        MessageUtil::setMessage("Category name is required.");
        LocationUtils::reload();
    }

    $data = [
        "name" => $name,
        "description" => $description ?: null
    ];

    if ($categoryRepo->update($data, ["id" => $id])) {
        MessageUtil::setMessage("Category updated successfully.");
        LocationUtils::redirectInternal("panel/multimedia-sessions/categories");
    } else {
        MessageUtil::setMessage("Error updating category. Please try again.");
        LocationUtils::reload();
    }
});

$router->run();




