<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceEventsRepository;
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

    $ServiceId = $_GET["id"];

    $Service = $ServiceRepo->getOne([
        "id"=> $ServiceId,
        "user_id"=>$user->getId()
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/Services/home");
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
   $id = $_POST['id'];
   $ServiceId = $_GET['id'];
   $repo = new ServicePromotionsRepository();

   $promotion = $repo->getOne([
       "id" => $id
   ]);

   if (is_null($promotion)) {
       MessageUtil::setMessage("Promotion not found");
       LocationUtils::redirectInternal('panel/service-promotions/home?id=' . $ServiceId);
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
