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

    $ServicePromotionsRepository = new ServicePromotionsRepository();
    $ServiceRepo = new ServiceRepository();
    $user = LoginService::getSession();

    $ServiceId = $_GET["id"] ?? null;
    if (!$ServiceId) {
        MessageUtil::setMessage("Missing service ID");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $Service = $ServiceRepo->getOne(["id" => $ServiceId]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    // Si no es admin, validar que sea el dueño
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $promotion = $ServicePromotionsRepository->getAllBy([
       "service_id" => $ServiceId
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $promotion,
        "service" => $Service
    ]);

});

$router->post(function () {
    $id = $_POST['id'] ?? null;
    $ServiceId = $_GET['id'] ?? null;

    if (!$id || !$ServiceId) {
        MessageUtil::setMessage("Missing parameters");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $repo = new ServicePromotionsRepository();
    $ServiceRepo = new ServiceRepository();
    $user = LoginService::getSession();

    $promotion = $repo->getOne(["id" => $id]);
    $Service = $ServiceRepo->getOne(["id" => $ServiceId]);

    if (is_null($promotion) || is_null($Service)) {
        MessageUtil::setMessage("Promotion or service not found");
        LocationUtils::redirectInternal('panel/service-promotions/home?id=' . $ServiceId);
    }

    // Si no es admin, validar que sea el dueño
    if ($user->getLevel() !== 1 && $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Access denied");
        LocationUtils::redirectInternal("panel/service/home");
    }

    FileUtils::removeFile($promotion->image);
    $repo->delete(["id" => $id]);

    MessageUtil::setMessage("Promotion deleted");
    LocationUtils::redirectInternal('panel/service-promotions/home?id=' . $ServiceId);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
