<?php

use App\Repositories\ServicePromotionsRepository;
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

    $Service = $ServiceRepo->getOne(["id" => $ServiceId]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service");
    }

    // Si no es admin, validar que sea el dueño
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "service" => $Service
    ]);
});

$router->post(function () {

    $ServicePromotionsRepository = new ServicePromotionsRepository();
    $ServiceRepo = new ServiceRepository();
    $ServiceId = $_GET['service'];
    $user = LoginService::getSession();

    $Service = $ServiceRepo->getOne(["id" => $ServiceId]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service");
    }

    // Si no es admin, validar que sea el dueño
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service");
    }

    if (empty($_FILES)) {
        MessageUtil::setMessage("No file uploaded");
        LocationUtils::redirectInternal("panel/service-promotions/create?service=$ServiceId");
    }

    $file = FileUtils::saveFile($_FILES["image"], "promotions");

    $ServicePromotionsRepository->add([
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "description" => $_POST['description'],
        "service_id" => $ServiceId,
        "image" => $file
    ]);

    MessageUtil::setMessage("Promotion created");
    LocationUtils::redirectInternal("panel/service-promotions?id=$ServiceId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
