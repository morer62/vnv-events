<?php

use App\Repositories\StorageContainerRepository;
use App\Repositories\StorageItemRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();

 
    $containerRepo = new StorageContainerRepository();
    $container = $containerRepo->getOne([
        "id" => $_GET["container"] ?? null,
        ...LoginService::getOwnerAsArray()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "container_id" => $container->id,
        ...$context
    ]);
});

$router->post(function () {
    $context = UserContext::get();

  
    $repo = new StorageItemRepository();

    $name = $_POST["name"] ?? "";
    $quantity = intval($_POST["quantity"] ?? 0);
    $containerId = $_GET["container"] ?? null;

    if ($name === "" || !$containerId || $quantity <= 0) {
        MessageUtil::setMessage("All fields are required.");
        LocationUtils::reload();
    }

    $repo->add([
        "id_container" => $containerId,
        "name" => $name,
        "quantity" => $quantity,
        ...LoginService::getOwnerAsArray()
    ]);

    MessageUtil::setMessage("Item created.");
    LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers/items?container_id=$containerId");
});

$router->run();
