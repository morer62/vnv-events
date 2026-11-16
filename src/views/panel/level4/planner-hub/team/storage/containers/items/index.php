<?php

use App\Repositories\StorageContainerRepository;
use App\Repositories\StorageItemRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $containerId = $_GET["container_id"] ?? null;

    if ($containerId && $user->hasPermissionForModule('storage')) {
        LocationUtils::redirectInternal(
            "panel/planner-hub/management/storage/containers/items?container_id=" . (int)$containerId
        );
    }

    $storageItemRepository = new StorageItemRepository();
    $containerRepo = new StorageContainerRepository();

    if (!$containerId) {
        MessageUtil::setMessage("Missing container ID.");
        LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers");
    }

    $container = $containerRepo->getOne([
       "id" => $containerId,
       "id_owner" => $user->getOwner()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers");
    }

    $items = $storageItemRepository->getAllBy([
        "id_container" => $containerId
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "items" => $items,
        "containerId" => $containerId,
        "container" => $container
    ]);
});

$router->post(function () {
    $itemId = $_POST["id"] ?? null;
    $containerId = $_GET["container_id"] ?? null;

    $containerRepo = new StorageContainerRepository();
    $storageItemRepository = new StorageItemRepository();
    $user = LoginService::getSession();

    if (!$user->hasPermissionForModule('storage')) {
        MessageUtil::setMessage("Storage management access is required to change container items.");
        LocationUtils::redirectInternal("panel/home");
    }

    $container = $containerRepo->getOne([
        "id" => $containerId,
        "id_owner" => $user->getOwner()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers");
    }

    if (is_null($itemId)) {
        MessageUtil::setMessage("Missing item ID.");
        LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers/items?container_id={$containerId}");
    }

    $item = $storageItemRepository->getOne([
       "id" => $itemId,
       "id_container" => $containerId,
    ]);

    if (!$item) {
        MessageUtil::setMessage("Item not found.");
        LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers/items?container_id={$containerId}");
    }

    $storageItemRepository->delete([
       "id" => $itemId
    ]);

    MessageUtil::setMessage("Item deleted.");
    LocationUtils::reload();
});

$router->run();
