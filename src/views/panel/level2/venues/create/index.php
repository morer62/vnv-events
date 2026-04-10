<?php

use App\Repositories\VenueRepository;
use App\Repositories\VenueCategoriesRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\VenueAmenitiesRepository;
use App\Repositories\VenueServicesRepository;
use App\Repositories\VenueAvailabilityRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\FormatPhone;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\PlatformDetector;
use App\Utils\PlacesUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    if (!$user->hasActivePaidMembership()) {
        MessageUtil::setMessage("You need an active membership to create venues. Please purchase a membership plan.");
        LocationUtils::redirectInternal("panel/membership/pay");
    }

    $venueRepo = new VenueRepository();
    $existingVenues = $venueRepo->getAllWithPaymentStatusByUser($user->getId());
    
    if (count($existingVenues) > 0) {
        MessageUtil::setMessage("You can only have one venue. You already have a venue registered.");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    $cardRepo = new UserCardsRepository();
    if (count($cardRepo->getByUserId($user->getId())) === 0) {
        MessageUtil::setMessage("You must add a payment method before creating your venue.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $categoryRepo = new VenueCategoriesRepository();
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categoryRepo->getAll(),
        "message" => MessageUtil::getMessage()
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();

    if (PlatformDetector::isMobileApp()) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Venue creation is not available in the mobile app. Please visit our website."
        ], 403);
    }

    if (!$user->hasActivePaidMembership()) {
        MessageUtil::setMessage("You need an active membership to create venues.");
        LocationUtils::redirectInternal("panel/membership/pay");
    }

    $cardRepo = new UserCardsRepository();
    $card = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => 'yes'
    ]);

    if (!$card) {
        MessageUtil::setMessage("You must add a payment method before creating a venue.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $venueRepo = new VenueRepository();
    $amenityRepo = new VenueAmenitiesRepository();
    $serviceRepo = new VenueServicesRepository();
    $availabilityRepo = new VenueAvailabilityRepository();

    $address = $_POST["address"] ?? "";
    try {
        [$lat, $lng] = PlacesUtils::getCoordinates($address);
    } catch (Exception) {
        MessageUtil::setMessage("Could not get coordinates for address: $address");
        LocationUtils::redirectInternal("panel/venues/create");
    }

    $renewal = date("Y-m-d", strtotime("+".$_ENV["FREE_DAYS_BEFORE_RENEWAL_PLANNER_HUB"]." days"));

    $venueRepo->add([
        "name" => $_POST["name"],
        "description" => $_POST["description"],
        "address" => $address,
        "category_id" => $_POST["category_id"],
        "phone_number" => FormatPhone::formatPhone($_POST["phone_number"]),
        "email" => $_POST["email"],
        "website" => $_POST["website"],
        "instagram" => $_POST["instagram"],
        "facebook" => $_POST["facebook"],
        "twitter" => $_POST["twitter"],
        "yelp" => $_POST["yelp"],
        "lat" => $lat,
        "lng" => $lng,
        "user_id" => $user->getId(),
        "status" => VenueRepository::APPROVED,
        "expiration_date" => $renewal,
        "business_name" => $_POST["business_name"],
        "ein" => $_POST["ein"],
        "capacity" => $_POST["capacity"] ?? null,
        "base_price" => $_POST["price"] ?? 0,
        "indoor_outdoor_type" => $_POST["indoor_outdoor_type"] ?? null,
    ]);

    $venueId = $venueRepo->getLastId();

    \App\Services\NotificationService::sendToUsers(
        [$user->getId()],
        '🎉 Venue Approved',
        'Your venue has been approved and is now live! You can start receiving leads.'
    );

    // Save amenities
    foreach ($_POST["amenities"] ?? [] as $item) {
        $amenityRepo->add([
            "venue_id" => $venueId,
            "amenity" => trim($item)
        ]);
    }

    // Save included services
    foreach ($_POST["services"] ?? [] as $item) {
        $serviceRepo->add([
            "venue_id" => $venueId,
            "service" => trim($item)
        ]);
    }

    // Save availability
    foreach ($_POST["availability"] ?? [] as $day => $info) {
        $availabilityRepo->add([
            "venue_id" => $venueId,
            "day" => $day,
            "opens" => $info["from"] ?? null,
            "closes" => $info["to"] ?? null,
            "is_closed" => isset($info["closed"]) ? 1 : 0
        ]);
    }

    $extra = $_POST['extra_categories'] ?? [];
    if (!is_array($extra)) {
        $extra = [];
    }
    $mainCategory = $_POST['category_id'] ?? null;
    $extra = array_values(array_unique(array_filter(array_map('intval', $extra), function ($cid) use ($mainCategory) {
        return (string)$cid !== (string)$mainCategory;
    })));

    if (count($extra) > 0) {
        $db = new Connection();
        foreach ($extra as $cid) {
            $db->query("INSERT IGNORE INTO venue_categories_assigned (user_id, venue_id, category_id, created_at) VALUES (:uid, :vid, :cid, NOW())");
            $db->bind(":uid", (int)$user->getId());
            $db->bind(":vid", (int)$venueId);
            $db->bind(":cid", (int)$cid);
            $db->execute();
        }
    }

    MessageUtil::setMessage("Venue created successfully and payment processed.");
    LocationUtils::redirectInternal("panel/venue-photos/home?id=$venueId");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
