<?php

use App\Repositories\StorageContainerRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;


$router = new Router();

$router->get(function () {

    $containerRepo = new StorageContainerRepository();

    $containers = $containerRepo->getAllBy([
        "id_owner" => LoginService::getSession()->getId()
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "containers" => $containers
    ]);
});

$router->post(function () {
    $containerRepo = new StorageContainerRepository();
    $user = LoginService::getSession();


    $containerId = $_POST["id"] ?? null;

    $container = $containerRepo->getOne([
        "id" => $containerId,
        "id_owner" => $user->getId()
    ]);

    if (!$container) {
        MessageUtil::setMessage("Container not found.");
        LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers");
    }

    $containerRepo->delete([
        "id" => $containerId
    ]);

    MessageUtil::setMessage("Container deleted.");
    LocationUtils::redirectInternal("panel/planner-hub/team/storage/containers");
});

$router->run();