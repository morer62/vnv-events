<?php

use App\Repositories\VenueRepository;
use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueAmenitiesRepository;
use App\Repositories\VenueServicesRepository;
use App\Repositories\VenueAvailabilityRepository;
use App\Repositories\Connection;
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
        LocationUtils::redirectInternal("panel/venues/home");
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

    // Cargar subcategorías asignadas para pre-chequear
    $db = new Connection();
    $db->query("SELECT category_id FROM venue_categories_assigned WHERE venue_id = :vid");
    $db->bind(":vid", (int)$id);
    $assignedRows = $db->fetchAll();
    $assignedCategoryIds = array_map(function($r){ return is_object($r) ? (int)$r->category_id : (int)$r['category_id']; }, $assignedRows ?: []);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $venue,
        "categories" => $categoryRepo->getAll(),
        "assignedCategoryIds" => $assignedCategoryIds
    ]);
});

$router->post(function () {
    $id = $_GET["id"];
    $user = LoginService::getSession();

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

    // Sincronizar subcategorías seleccionadas en checklist
    try {
        $selected = $_POST['extra_categories'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }
        // Normalizar a enteros únicos
        $selected = array_values(array_unique(array_map('intval', $selected)));

        $db = new Connection();

        // 1) Insertar marcadas (si faltan)
        foreach ($selected as $cid) {
            $db->query("INSERT IGNORE INTO venue_categories_assigned (user_id, venue_id, category_id, created_at) VALUES (:uid, :vid, :cid, NOW())");
            $db->bind(":uid", (int)$user->getId());
            $db->bind(":vid", (int)$id);
            $db->bind(":cid", (int)$cid);
            $db->execute();
        }

        // 2) Eliminar solo las que fueron explícitamente desmarcadas
        // Obtenemos las actuales
        $db->query("SELECT category_id FROM venue_categories_assigned WHERE venue_id = :vid");
        $db->bind(":vid", (int)$id);
        $currentRows = $db->fetchAll();
        $current = array_map(function($r){ return is_object($r) ? (int)$r->category_id : (int)$r['category_id']; }, $currentRows ?: []);
        $toDelete = array_values(array_diff($current, $selected));
        if (count($toDelete) > 0) {
            $named = [];
            foreach ($toDelete as $idx => $cid) { $named[] = ":d$idx"; }
            $inClause = implode(',', $named);
            $sql = "DELETE FROM venue_categories_assigned WHERE venue_id = :vid AND category_id IN ($inClause)";
            $db->query($sql);
            $db->bind(":vid", (int)$id);
            foreach ($toDelete as $idx => $cid) { $db->bind(":d$idx", (int)$cid); }
            $db->execute();
        }
    } catch (\Throwable $e) {
        MessageUtil::setMessage("There was an error updating categories: " . $e->getMessage());
        LocationUtils::redirectInternal("panel/venues/edit?id=" . urlencode((string)$id));
    }

    MessageUtil::setMessage("Venue updated");
    LocationUtils::redirectInternal("panel/venues/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
