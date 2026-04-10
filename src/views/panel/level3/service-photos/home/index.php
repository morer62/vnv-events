<?php

use App\Repositories\ServicePhotosRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function uploadPhoto(): never {
    $ServiceId = $_GET["id"];
    $ServiceRepository = new ServiceRepository();
    $repo = new ServicePhotosRepository();
    $user = LoginService::getSession();

    $Service = $ServiceRepository->getOne([
        "id" => $ServiceId,
        "user_id" => $user->getId()
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    if (!FileUtils::hasFile($_FILES, "image")) {
        MessageUtil::setMessage("Photo not provided");
        LocationUtils::redirectInternal("panel/service-photos/home?id=$ServiceId");
    }

    $location = FileUtils::saveFile($_FILES["image"], "Service-images");
    $repo->add([
        "Service_id" => $ServiceId,
        "image" => $location,
    ]);

    MessageUtil::setMessage("Service photo added successfully");
    LocationUtils::redirectInternal("panel/service-photos/home?id=$ServiceId");
}

function deletePhoto(): never {
    $id = $_POST['id'];
    $ServiceId = $_GET["id"];
    $repo = new ServicePhotosRepository();

    $photo = $repo->getOne([
        "id" => $id
    ]);

    if (is_null($photo)) {
        MessageUtil::setMessage("Photo not found");
        LocationUtils::redirectInternal('panel/service-photos/home');
    }

    $repo->delete([
        "id" => $id
    ]);

    FileUtils::removeFile($photo->image);
    MessageUtil::setMessage("Photo deleted");
    LocationUtils::redirectInternal('panel/service-photos/home?id='.$ServiceId);
}

$router->get(function () {

    $repo = new ServicePhotosRepository();
    $ServiceRepository = new ServiceRepository();
    $user = LoginService::getSession();
    $ServiceId = $_GET["id"];


    $Service = $ServiceRepository->getOne([
       "user_id" => $user->getId(),
        "id" => $ServiceId
    ]);

    if (is_null($Service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }



    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "photos" => $repo->getAllBy([
            "Service_id" => $ServiceId
        ])
    ]);

});

$router->post(function () {
   if ($_POST["action"] == "delete") {
       deletePhoto();
   }

   if ($_POST["action"] == "uploadPhoto") {
       uploadPhoto();
   }
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
