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
    $ServiceEventRepo = new ServiceEventsRepository();

    $ServiceId = $_GET['service'];
    $eventId = $_GET['id'];

    $user = LoginService::getSession();

    $Service = $ServiceRepo->getOne(["id" => $ServiceId]);
    $event = $ServiceEventRepo->getOne(["id" => $eventId]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service");
    }

    if (is_null($event)) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/service-events?id=$ServiceId");
    }

    // Validar si es admin o dueño del servicio
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "service" => $Service,
        "event" => $event,
    ]);
});

$router->post(function () {
    $ServiceEventRepo = new ServiceEventsRepository();
    $ServiceRepo = new ServiceRepository();
    $user = LoginService::getSession();

    $ServiceId = $_GET['service'];
    $eventId = $_GET['id'];

    $Service = $ServiceRepo->getOne(["id" => $ServiceId]);
    $event = $ServiceEventRepo->getOne(["id" => $eventId]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service");
    }

    if (is_null($event)) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/service-events?id=$ServiceId");
    }

    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service");
    }

    $file = $event->image;

    if (FileUtils::hasFile($_FILES, "image")) {
        FileUtils::removeFile($file);
        $file = FileUtils::saveFile($_FILES["image"], "services");
    }

    $ServiceEventRepo->update([
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "description" => $_POST['description'],
        "external_link" => $_POST['external_link'],
        "image" => $file
    ], [
        "id" => $eventId
    ]);

    MessageUtil::setMessage("Event Edited");
    LocationUtils::redirectInternal("panel/service-events?id=$ServiceId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
