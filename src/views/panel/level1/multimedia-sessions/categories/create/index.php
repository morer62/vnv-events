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
    return TemplateResponse::render(__DIR__ . "/index.twig", [...$context]);
});

$router->post(function () {
    $categoryRepo = new MusicSessionsCategoryRepository();

    $name = trim($_POST["name"] ?? '');
    $description = trim($_POST["description"] ?? '');

    if (empty($name)) {
        MessageUtil::setMessage("Category name is required.");
        LocationUtils::reload();
    }

    $data = [
        "name" => $name,
        "description" => $description ?: null,
        ...LoginService::getUserIdAsArray(true)
    ];

    if ($categoryRepo->add($data)) {
        MessageUtil::setMessage("Category created successfully.");
        LocationUtils::redirectInternal("panel/multimedia-sessions/categories");
    } else {
        MessageUtil::setMessage("Error creating category. Please try again.");
        LocationUtils::reload();
    }
});

$router->run();

