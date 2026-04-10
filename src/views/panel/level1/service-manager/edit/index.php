<?php

use App\Repositories\ServiceRepository;
use App\Repositories\ServiceZipPaymentsRepository;
use App\Repositories\ServiceCategoriesRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\PlacesUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Service not found.");
        LocationUtils::redirectInternal("panel/service");
    }

    $serviceRepo = new ServiceRepository();
    $zipRepo = new ServiceZipPaymentsRepository();
    $categoryRepo = new ServiceCategoriesRepository();

    $service = $serviceRepo->getOne(["id" => $id]);

    if (!$service) {
        MessageUtil::setMessage("Service not found.");
        LocationUtils::redirectInternal("panel/service");
    }

    $cities = $zipRepo->getAllByService($service->id);
    $formattedCities = array_map(fn($c) => [
        "slug" => $c->city_slug,
        "display" => $c->city_display
    ], $cities);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "service" => $service,
        "cities_json" => json_encode($formattedCities),
        "categories" => $categoryRepo->getAll()
    ]);
});

$router->post(function () {
    $serviceId = $_GET["id"] ?? null;

    if (!$serviceId) {
        MessageUtil::setMessage("Invalid service.");
        LocationUtils::redirectInternal("panel/service");
    }

    $serviceRepository = new ServiceRepository();
    $serviceZipPaymentRepo = new ServiceZipPaymentsRepository();
    $cities = json_decode($_POST["zip_codes"], true);

    try {
        [$lat, $long] = PlacesUtils::getCoordinates($_POST["address"]);
    } catch (Exception) {
        [$lat, $long] = [null, null];
    }

    $serviceRepository->update([
        "name" => $_POST["name"],
        "description" => $_POST["description"],
        "address" => $_POST["address"],
        "phone_number" => $_POST["phone_number"],
        "email" => $_POST["email"],
        "website" => $_POST["website"],
        "instagram" => $_POST["instagram"],
        "facebook" => $_POST["facebook"],
        "twitter" => $_POST["twitter"],
        "yelp" => $_POST["yelp"],
        "lat" => $lat,
        "lng" => $long,
        "category_id" => $_POST["category_id"]
    ], [ "id" => $serviceId ]);

    $serviceZipPaymentRepo->delete([ "id_service" => $serviceId ]);

    foreach ($cities as $city) {
        $serviceZipPaymentRepo->add([
            "city_slug" => $city["slug"],
            "city_display" => $city["display"],
            "id_service_category" => $_POST["category_id"],
            "id_service" => $serviceId,
            "status" => ServiceZipPaymentsRepository::PENDING
        ]);
    }

    MessageUtil::setMessage("Service updated successfully.");
    LocationUtils::redirectInternal("panel/service");
});

$router->run();
