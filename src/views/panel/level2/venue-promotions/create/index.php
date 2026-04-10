<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueEventsRepository;
use App\Repositories\VenuePromotionsRepository;
use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $venueRepo = new VenueRepository();
    $venueId = $_GET['venue'];
    $user = LoginService::getSession();

    $venue = $venueRepo->getOne([
        "id" => $venueId,
        'user_id' => $user->getId()
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venue" => $venue
    ]);
});

$router->post(function () {

    $venuePromotionsRepository = new VenuePromotionsRepository();
    $venueRepo = new VenueRepository();
    $venueId = $_GET['venue'];
    $user = LoginService::getSession();

    $venue = $venueRepo->getOne([
        "id" => $venueId,
        'user_id' => $user->getId()
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    if (empty($_FILES)) {
        MessageUtil::setMessage("No file uploaded");
        LocationUtils::redirectInternal("panel/venue-promotions/create?venue=$venueId");
    }

    $file = FileUtils::saveFile($_FILES["image"], "promotions");

    $venuePromotionsRepository->add([
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "description" => $_POST['description'],
        "venue_id" => $venueId,
        "image" => $file
    ]);

    MessageUtil::setMessage("Promotion created");
    LocationUtils::redirectInternal("panel/venue-promotions/home?id=$venueId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
