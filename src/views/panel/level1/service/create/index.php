<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\Connection;
use App\Repositories\UserCardsRepository;
use App\Services\LoginService;
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

    $catRepo = new ServiceCategoriesRepository();
    $cardRepo = new UserCardsRepository();

    $cards = $cardRepo->getOne([
        'id_user' => $user->getId(),
        'main_card' => 'yes'
    ]);

    if (is_null($cards)) {
        MessageUtil::setMessage("You must add a payment method before creating a service.");
        LocationUtils::redirectInternal("panel/cards");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $catRepo->getAll()
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();

    if (PlatformDetector::isMobileApp()) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Service creation is not available in the mobile app. Please visit our website."
        ], 403);
    }

    $serviceRepository = new ServiceRepository();

    // Obtener coordenadas de la dirección
    try {
        [$lat, $lng] = PlacesUtils::getCoordinates($_POST["address"]);
    } catch (Exception) {
        [$lat, $lng] = [null, null];
    }

    // Guardar servicio
    $serviceRepository->add([
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
        "lng" => $lng,
        "category_id" => $_POST["category_id"],
        "user_id" => $user->getId(),
        "status" => ServiceRepository::PENDING
    ]);

    // Guardar subcategorías seleccionadas (sin incluir la principal)
    $serviceId = $serviceRepository->getLastId();
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
            $db->query("INSERT IGNORE INTO service_categories_assigned (user_id, service_id, category_id, created_at) VALUES (:uid, :sid, :cid, NOW())");
            $db->bind(":uid", (int)$user->getId());
            $db->bind(":sid", (int)$serviceId);
            $db->bind(":cid", (int)$cid);
            $db->execute();
        }
    }

    MessageUtil::setMessage("Service created successfully.");
    LocationUtils::redirectInternal("panel/service/home");
});

$router->run();
