<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueEventsRepository;
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
    $venueEventRepo = new VenueEventsRepository();

    $venueId = $_GET['venue'];
    $eventId = $_GET['id'];

   // $user = LoginService::getSession();

    $venue = $venueRepo->getOne([
        "id" => $venueId,
        //'user_id' => $user->getId()
    ]);

    $event = $venueEventRepo->getOne([
        "id" => $eventId,
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    if (is_null($event)) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/venue-events/home?id=$venueId");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venue" => $venue,
        "event" => $event,
    ]);
});

$router->post(function () {
    $venueEventRepo = new VenueEventsRepository();
    $venueRepo = new VenueRepository();
   // $user = LoginService::getSession();

    $venueId = $_GET['venue'];
    $eventId = $_GET['id'];

    $venue = $venueRepo->getOne([
        "id" => $venueId,
     //   'user_id' => $user->getId()
    ]);

    $event = $venueEventRepo->getOne([
        "id" => $eventId,
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    if (is_null($event)) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/venue-events/home?id=$venueId");
    }

    $file = $event->image;

    if (FileUtils::hasFile($_FILES, "image")) {
        FileUtils::removeFile($file);
        $file = FileUtils::saveFile($_FILES["image"], "venues");
    }

    $venueEventRepo->update(data: [
        "start_date" => $_POST['start_date'],
        "end_date" => $_POST['end_date'],
        "name" => $_POST['name'],
        "image" => $file
    ], criteriaVals: [
        "id" => $eventId,
    ]);

    MessageUtil::setMessage("Event Edited");
    LocationUtils::redirectInternal("panel/venue-events/home?id=$venueId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
