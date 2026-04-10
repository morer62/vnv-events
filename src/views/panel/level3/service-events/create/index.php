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
        "id" => $ServiceId,
        'user_id' => $user->getId()
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "Service" => $Service
    ]);
});

$router->post(function () {

    $ServiceEventRepo = new ServiceEventsRepository();
    $ServiceRepo = new ServiceRepository();
    $ServiceId = $_GET['service'];
    $user = LoginService::getSession();

    $Service = $ServiceRepo->getOne([
        "id" => $ServiceId,
        'user_id' => $user->getId()
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    if (empty($_FILES)) {
        MessageUtil::setMessage("No file uploaded");
        LocationUtils::redirectInternal("panel/service-events/create?Service=$ServiceId");
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
    LocationUtils::redirectInternal("panel/service-events/home?id=$ServiceId");
}); 

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
