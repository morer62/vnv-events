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

    if ($context["level"] === 3 && !$context["can"]("storage", "edit")) {
        LocationUtils::redirectInternal("panel/no-access");
        return "";
    }

    $containerRepo = new StorageContainerRepository();

    $container = $containerRepo->getOne([
        "id" => $_GET["id"] ?? null,
        ...LoginService::getOwnerAsArray()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "container" => $container,
        ...$context
    ]);
});

$router->post(function () {
    $context = UserContext::get();

    if ($context["level"] === 3 && !$context["can"]("storage", "edit")) {
        LocationUtils::redirectInternal("panel/no-access");
        return "";
    }

    $containerRepo = new StorageContainerRepository();
    $user = LoginService::getSession();

    $containerId = $_GET["id"] ?? null;
    $name = trim($_POST["name"] ?? "");

    if (!$containerId || $name === "") {
        MessageUtil::setMessage("All fields are required.");
        LocationUtils::reload();
    }

    $container = $containerRepo->getOne([
        "id" => $containerId,
        ...LoginService::getOwnerAsArray()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
    }

    $updateData = ["name" => $name];

    if (\App\Utils\FileUtils::hasFile($_FILES, "img_reference")) {
        try {
            // Opcional: borrar la anterior
            if (!empty($container->img_reference)) {
                \App\Utils\FileUtils::removeFile($container->img_reference);
            }

            $imgPath = \App\Utils\FileUtils::saveFile($_FILES["img_reference"], "container_img_reference");
            $updateData["img_reference"] = $imgPath;
        } catch (Exception $e) {
            MessageUtil::setMessage("Error uploading image: " . $e->getMessage());
            LocationUtils::reload();
        }
    }

    $containerRepo->update($updateData, ["id" => $containerId]);

    MessageUtil::setMessage("Container updated.");
    LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
});


$router->run();
