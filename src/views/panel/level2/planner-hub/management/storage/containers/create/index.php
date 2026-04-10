<?php

use App\Repositories\StorageContainerRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();

   
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context
    ]);
});

$router->post(function () {
    $context = UserContext::get();
    $repo = new StorageContainerRepository();
    $user = LoginService::getSession();

    $name = trim($_POST["name"] ?? '');

    if (empty($name)) {
        MessageUtil::setMessage("Container name is required.");
        LocationUtils::reload();
    }

    $imgPath = null;

    if (!empty($_FILES["img_reference"]["tmp_name"])) {
        $imgPath = \App\Utils\FileUtils::saveFile(
            $_FILES["img_reference"],
            "container_img_reference"
        );
    }

    $repo->add([
        "name" => $name,
        "img_reference" => $imgPath,
        ...LoginService::getOwnerAsArray()
    ]);

    MessageUtil::setMessage("Container created successfully!");
    LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
});


$router->run();
