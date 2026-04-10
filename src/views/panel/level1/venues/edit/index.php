<?php

use App\Repositories\VenueRepository;
use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueAmenitiesRepository;
use App\Repositories\VenueServicesRepository;
use App\Repositories\VenueAvailabilityRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\PlacesUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET["id"];

    $categoryRepo = new VenueCategoriesRepository();
    $venueRepo = new VenueRepository();
    $amenityRepo = new VenueAmenitiesRepository();
    $serviceRepo = new VenueServicesRepository();
    $availabilityRepo = new VenueAvailabilityRepository();

    $venue = $venueRepo->getOne(["id" => $id]);
    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues");
    }

    $venue->amenities = array_map(fn($a) => $a->amenity, $amenityRepo->getAllBy(["venue_id" => $id]));
    $venue->services = array_map(fn($s) => $s->service, $serviceRepo->getAllBy(["venue_id" => $id]));

    $venue->availability = [];
    foreach ($availabilityRepo->getAllBy(["venue_id" => $id]) as $item) {
        $venue->availability[$item->day] = [
            "from" => $item->opens,
            "to" => $item->closes,
            "closed" => $item->is_closed
        ];
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $venue,
        "categories" => $categoryRepo->getAll()
    ]);
});

$router->post(function () {
    $id = $_GET["id"];
    $venueRepo = new VenueRepository();
    $amenityRepo = new VenueAmenitiesRepository();
    $serviceRepo = new VenueServicesRepository();
    $availabilityRepo = new VenueAvailabilityRepository();

    $lat = null;
    $lng = null;
    try {
        [$lat, $lng] = PlacesUtils::getCoordinates($_POST["address"]);
    } catch (Exception) {}

    $venueRepo->update([
        "name" => $_POST["name"],
        "description" => $_POST["description"],
        "address" => $_POST["address"],
        "lat" => $lat,
        "lng" => $lng,
        "phone_number" => $_POST["phone_number"],
        "email" => $_POST["email"],
        "website" => $_POST["website"],
        "instagram" => $_POST["instagram"],
        "facebook" => $_POST["facebook"],
        "twitter" => $_POST["twitter"],
        "yelp" => $_POST["yelp"],
        "category_id" => $_POST["category_id"],
        "business_name" => $_POST["business_name"],
        "ein" => $_POST["ein"],
        "capacity" => $_POST["capacity"],
        "base_price" => $_POST["price"],
        "indoor_outdoor_type" => $_POST["indoor_outdoor_type"]
    ], ["id" => intval($id)]);

    $amenityRepo->delete(["venue_id" => $id]);
    foreach ($_POST["amenities"] ?? [] as $item) {
        $amenityRepo->add(["venue_id" => $id, "amenity" => trim($item)]);
    }

    $serviceRepo->delete(["venue_id" => $id]);
    foreach ($_POST["services"] ?? [] as $item) {
        $serviceRepo->add(["venue_id" => $id, "service" => trim($item)]);
    }

    $availabilityRepo->delete(["venue_id" => $id]);
    foreach ($_POST["availability"] ?? [] as $day => $info) {
        $availabilityRepo->add([
            "venue_id" => $id,
            "day" => $day,
            "opens" => $info["from"] ?? null,
            "closes" => $info["to"] ?? null,
            "is_closed" => isset($info["closed"]) ? 1 : 0
        ]);
    }

    MessageUtil::setMessage("Venue updated");
    LocationUtils::redirectInternal("panel/venues");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
