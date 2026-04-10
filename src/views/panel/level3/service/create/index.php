<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\Connection;
use App\Repositories\UserCardsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\NotificationService;
use App\Services\StripeService;
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
        MessageUtil::setMessage("You need an active membership to create services. Please purchase a membership plan.");
        LocationUtils::redirectInternal("panel/membership/pay");
    }

    $serviceRepo = new ServiceRepository();
    $existingServices = $serviceRepo->getAllBy(["user_id" => $user->getId()]);
    
    if (count($existingServices) > 0) {
        MessageUtil::setMessage("You can only have one service. You already have a service registered.");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $catRepo = new ServiceCategoriesRepository();
    $cardRepo = new UserCardsRepository();

    $cards = $cardRepo->getOne([
        'id_user' => $user->getId(),
        'main_card' => 'yes'
    ]);

    if (!$cards && PlatformDetector::isMobileApp()) {
        return TemplateResponse::renderInTemplates("web-only-feature.twig",[
            "title" => "Configuration",
            "message" => "Service creation needs additional configuration",
            "showWebLink" => false
        ]);
    }

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
    
    if (!$user->hasActivePaidMembership()) {
        MessageUtil::setMessage("You need an active membership to create services.");
        LocationUtils::redirectInternal("panel/membership/pay");
    }

    $cardRepo = new UserCardsRepository();
    $card = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => 'yes'
    ]);

    if (!$card && PlatformDetector::isMobileApp()) {
        return TemplateResponse::renderInTemplates("web-only-feature.twig",[
            "title" => "Configuration",
            "message" => "Service creation needs additional configuration",
            "showWebLink" => false
        ]);
    }

    if (!$card) {
        MessageUtil::setMessage("You must add a payment method before creating a service.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $serviceRepository = new ServiceRepository();

    try {
        [$lat, $lng] = PlacesUtils::getCoordinates($_POST["address"]);
    } catch (Exception) {
        [$lat, $lng] = [null, null];
    }

    $renewal = date("Y-m-d", strtotime("+".$_ENV["FREE_DAYS_BEFORE_RENEWAL_LISTING"]." days"));

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
        "status" => ServiceRepository::APPROVED,
        "expiration_date" => $renewal
    ]);

    $serviceId = $serviceRepository->getLastId();

    NotificationService::sendToUsers(
        [$user->getId()],
        '🎉 Service Approved',
        'Your service has been approved and is now live! You can start receiving leads.'
    );

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
