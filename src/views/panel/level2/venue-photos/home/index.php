<?php

use App\Repositories\VenuePhotosRepository;
use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function uploadPhoto(): never {
    $venueId = $_GET["id"];
    $venueRepository = new VenueRepository();
    $repo = new VenuePhotosRepository();
    $user = LoginService::getSession();

    $venue = $venueRepository->getOne([
        "id" => $venueId,
        "user_id" => $user->getId()
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    if (!FileUtils::hasFile($_FILES, "image")) {
        MessageUtil::setMessage("Photo not provided");
        LocationUtils::redirectInternal("panel/venue-photos/home?id=$venueId");
    }

    $location = FileUtils::saveFile($_FILES["image"], "venue-images");
    $repo->add([
        "venue_id" => $venueId,
        "image" => $location,
    ]);

    MessageUtil::setMessage("Venue photo added successfully");
    LocationUtils::redirectInternal("panel/venue-photos/home?id=$venueId");
}

function deletePhoto(): never {
    $id = $_POST['id'];
    $venueId = $_GET["id"];
    $repo = new VenuePhotosRepository();

    $photo = $repo->getOne([
        "id" => $id
    ]);

    if (is_null($photo)) {
        MessageUtil::setMessage("Photo not found");
        LocationUtils::redirectInternal('panel/venue-photos/home');
    }

    $repo->delete([
        "id" => $id
    ]);

    FileUtils::removeFile($photo->image);
    MessageUtil::setMessage("Photo deleted");
    LocationUtils::redirectInternal('panel/venue-photos/home?id='.$venueId);
}

$router->get(function () {

    $repo = new VenuePhotosRepository();
    $venueRepository = new VenueRepository();
    $user = LoginService::getSession();
    $venueId = $_GET["id"];


    $venue = $venueRepository->getOne([
       "user_id" => $user->getId(),
        "id" => $venueId
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }



    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "photos" => $repo->getAllBy([
            "venue_id" => $venueId
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
