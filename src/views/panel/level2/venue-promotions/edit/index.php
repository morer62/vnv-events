<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueEventsRepository;
use App\Repositories\VenuePromotionsRepository;
use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Utils\CSRF;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $venueRepo = new VenueRepository();
    $venuePromotionsRepository = new VenuePromotionsRepository();

    $venueId = $_GET['venue'];
    $eventId = $_GET['id'];

    $user = LoginService::getSession();

    $venue = $venueRepo->getOne([
        "id" => $venueId,
        'user_id' => $user->getId()
    ]);

    $promotion = $venuePromotionsRepository->getOne([
        "id" => $eventId,
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    if (is_null($promotion)) {
        MessageUtil::setMessage("Promotion not found");
        LocationUtils::redirectInternal("panel/venue-promotions/home?id=$venueId");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venue" => $venue,
        "event" => $promotion,
    ]);
});

$router->post(function () {
    CSRF::validateCSRF();
    $venuePromotionRepo = new VenuePromotionsRepository();
    $venueRepo = new VenueRepository();
    $user = LoginService::getSession();

    $venueId = $_GET['venue'];
    $eventId = $_GET['id'];

    $venue = $venueRepo->getOne([
        "id" => $venueId,
        'user_id' => $user->getId()
    ]);

    $promotion = $venuePromotionRepo->getOne([
        "id" => $eventId,
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    if (is_null($promotion)) {
        MessageUtil::setMessage("Promotion not found");
        LocationUtils::redirectInternal("panel/venue-promotions/home?id=$venueId");
    }

    $file = $promotion->image;

    if (FileUtils::hasFile($_FILES, "image")) {
        FileUtils::removeFile($file);
        $file = FileUtils::saveFile($_FILES["image"], "promotions");
    }

    $venuePromotionRepo->update(data: [
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "description" => $_POST['description'],
        "image" => $file
    ], criteriaVals: [
        "id" => $eventId,
    ]);

    MessageUtil::setMessage("Event Edited");
    LocationUtils::redirectInternal("panel/venue-promotions/home?id=$venueId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
