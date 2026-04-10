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
    $ServiceEventRepo = new ServiceEventsRepository();
    $ServiceRepo = new ServiceRepository();
    $user = LoginService::getSession();

    $ServiceId = $_GET["id"];

    $Service = $ServiceRepo->getOne([
        "id" => $ServiceId
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    // Validación: si el usuario no es admin, debe ser el dueño del servicio
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $events = $ServiceEventRepo->getAllBy([
        "service_id" => $ServiceId
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $events,
        "service" => $Service
    ]);
});

$router->post(function () {
    $id = $_POST['id'];
    $ServiceId = $_GET['id'];
    $repo = new ServiceEventsRepository();

    $event = $repo->getOne([
        "id" => $id
    ]);

    if (is_null($event)) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal('panel/service-events/home?id=' . $ServiceId);
    }

    FileUtils::removeFile($event->image);
    $repo->delete(["id" => $id]);

    MessageUtil::setMessage("Event deleted");
    LocationUtils::redirectInternal('panel/service-events/home?id=' . $ServiceId);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
