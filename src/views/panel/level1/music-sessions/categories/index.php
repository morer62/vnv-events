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
    $user = LoginService::getSession();
    $categoryRepo = new MusicSessionsCategoryRepository();

    $userId = $user->getId();
    $categories = $categoryRepo->getAllByUser($userId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "categories" => $categories
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $categoryRepo = new MusicSessionsCategoryRepository();
    $id = $_POST["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Invalid category ID.");
        LocationUtils::reload();
    }

    $category = $categoryRepo->getOne(["id" => $id]);

    if (!$category) {
        MessageUtil::setMessage("Category not found.");
        LocationUtils::reload();
    }

    $categoryRepo->delete(["id" => $id]);

    MessageUtil::setMessage("Category deleted successfully.");
    LocationUtils::reload();
});

$router->run();

