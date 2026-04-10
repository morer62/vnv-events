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

   
    $containerRepo = new StorageContainerRepository();

    $containers = $containerRepo->getAllBy([
        ...LoginService::getOwnerAsArray()
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "containers" => $containers,
        ...$context
    ]);
});

$router->post(function () {
    $containerRepo = new StorageContainerRepository();
    $user = LoginService::getSession();

    $containerId = $_POST["id"] ?? null;

    $container = $containerRepo->getOne([
        "id" => $containerId,
        ...LoginService::getOwnerAsArray()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
    }

    $containerRepo->delete([
        "id" => $containerId
    ]);

    MessageUtil::setMessage("Container deleted.");
    LocationUtils::redirectInternal("panel/planner-hub/management/storage/containers");
});

$router->run();
