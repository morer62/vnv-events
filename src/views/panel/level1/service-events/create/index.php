<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceEventsRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $ServiceRepo = new ServiceRepository();
    $ServiceId = $_GET['service'];
    $user = LoginService::getSession();

    $Service = $ServiceRepo->getOne([
        "id" => $ServiceId
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service");
    }

    // Solo permitir si es admin o dueño del servicio
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "service" => $Service
    ]);
});

$router->post(function () {
    $ServiceEventRepo = new ServiceEventsRepository();
    $ServiceRepo = new ServiceRepository();
    $ServiceId = $_GET['service'];
    $user = LoginService::getSession();

    $Service = $ServiceRepo->getOne([
        "id" => $ServiceId
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service");
    }

    // Solo permitir si es admin o dueño del servicio
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service");
    }

    if (empty($_FILES)) {
        MessageUtil::setMessage("No file uploaded");
        LocationUtils::redirectInternal("panel/service-events/create?service=$ServiceId");
    }

    $file = FileUtils::saveFile($_FILES["image"], "Services");

    $ServiceEventRepo->add([
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "description" => $_POST['description'],
        "external_link" => $_POST['external_link'],
        "service_id" => $ServiceId,
        "image" => $file
    ]);

    MessageUtil::setMessage("Event created");
    LocationUtils::redirectInternal("panel/service-events?id=$ServiceId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
