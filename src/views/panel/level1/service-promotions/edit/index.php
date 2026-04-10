<?php

use App\Repositories\ServicePromotionsRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Utils\CSRF;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $ServiceRepo = new ServiceRepository();
    $ServicePromotionsRepository = new ServicePromotionsRepository();

    $ServiceId = $_GET['service'];
    $promotionId = $_GET['id'];

    $Service = $ServiceRepo->getOne([
        "id" => $ServiceId
    ]);

    $promotion = $ServicePromotionsRepository->getOne([
        "id" => $promotionId,
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    if (is_null($promotion)) {
        MessageUtil::setMessage("Promotion not found");
        LocationUtils::redirectInternal("panel/service-promotions/home?id=$ServiceId");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "service" => $Service,
        "event" => $promotion,
    ]);
});

$router->post(function () {
    CSRF::validateCSRF();
    $ServicePromotionRepo = new ServicePromotionsRepository();
    $ServiceRepo = new ServiceRepository();

    $ServiceId = $_GET['service'];
    $promotionId = $_GET['id'];

    $Service = $ServiceRepo->getOne([
        "id" => $ServiceId
    ]);

    $promotion = $ServicePromotionRepo->getOne([
        "id" => $promotionId,
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    if (is_null($promotion)) {
        MessageUtil::setMessage("Promotion not found");
        LocationUtils::redirectInternal("panel/service-promotions/home?id=$ServiceId");
    }

    $file = $promotion->image;

    if (FileUtils::hasFile($_FILES, "image")) {
        FileUtils::removeFile($file);
        $file = FileUtils::saveFile($_FILES["image"], "promotions");
    }

    $ServicePromotionRepo->update(data: [
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "description" => $_POST['description'],
        "image" => $file
    ], criteriaVals: [
        "id" => $promotionId,
    ]);

    MessageUtil::setMessage("Promotion Edited");
    LocationUtils::redirectInternal("panel/service-promotions/home?id=$ServiceId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
