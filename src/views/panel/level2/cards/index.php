<?php

use App\Repositories\UserCardsRepository;
use App\Repositories\UserBillingInfoRepository;
use App\Repositories\VenueRepository;
use App\Services\ClientPaymentMethodService;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\PlatformDetector;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    if (PlatformDetector::isMobileApp()) {
        return TemplateResponse::renderInTemplates("web-only-feature.twig", [
            "title" => "Payment Configuration Required",
            "message" => "To complete your venue or service setup, you need to configure your payment method. For security reasons, payment configurations can only be managed in the same system where your account was created.",
            "showWebLink" => false,
            "icon" => "💳",
            "websiteUrl" => $_ENV["APP_URL"] ?? "https://ophyra.com"
        ]);
    }

    $billingRepo = new UserBillingInfoRepository();
    $billing = $billingRepo->getByUserId($user->getId());

    if (!$billing) {
        LocationUtils::redirectInternal("panel/billing");
    }

    $cardRepo = new UserCardsRepository();
    $cards = $cardRepo->getByUserId($user->getId());
    $clientPaymentMethods = (new ClientPaymentMethodService())->listClientSavedPaymentMethodsAcrossBusinesses((int)$user->getId());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "stripe_key" => $_ENV["STRIPE_PUBLIC"],
        "cards" => $cards,
        "client_payment_methods" => $clientPaymentMethods,
        "websiteUrl" => $_ENV["APP_URL"] ?? "https://ophyra.com"
    ]);
});

$router->post(function () {
    try {
        $user = LoginService::getSession();
        
        if (PlatformDetector::isMobileApp()) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Payment methods management is not available in the mobile app. Please visit our website."
            ], 403);
        }
        
        $repo = new UserCardsRepository();

        if (isset($_POST["delete_client_payment_method"])) {
            (new ClientPaymentMethodService())->deactivateMethod((int)$_POST["delete_client_payment_method"], (int)$user->getId());
            LocationUtils::redirectInternal("panel/cards");
        }

        // Eliminar tarjeta
        if (isset($_POST["delete_card"])) {
            $repo->deleteCard(intval($_POST["delete_card"]));
            LocationUtils::redirectInternal("panel/cards");
        }

        // Establecer tarjeta principal
        if (isset($_POST["set_main"])) {
            $cardId = intval($_POST["set_main"]);
            $repo->setMainCard($user->getId(), $cardId);
            LocationUtils::redirectInternal("panel/cards");
        }

        // Agregar tarjeta
        $token = $_POST["token"] ?? "";
        $cardInfo = json_decode($_POST["card_info"] ?? '{}', true);

        if (!$token || empty($cardInfo)) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Token and card info are required"
            ]);
        }

        $stripe = new StripeService();
        $customer = $stripe->createCustomerWithCard($token, $user->getEmail());

        if (!$customer) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Card validation failed"
            ], 420);
        }

        // Si no hay tarjeta principal, esta será la principal
        $main = $repo->countCards($user->getId()) == 0 ? "yes" : "no";

        $repo->add([
            "id_user" => $user->getId(),
            "brand" => $cardInfo["brand"],
            "last4" => $cardInfo["last4"],
            "exp" => $cardInfo["exp"],
            "token" => $customer,
            "main_card" => $main
        ]);

        // Verificar si el usuario ya tiene al menos un VENUE (nivel 2)
        $venueRepo = new VenueRepository();
        $hasVenue = !!$venueRepo->getOne(["user_id" => $user->getId()]);

        $needsProfile = !$hasVenue;
        $nextTitle = $needsProfile ? "Create your venue profile" : "";
        $base = rtrim($_ENV["APP_URL"] ?? '', '/');
        $nextUrl = $needsProfile ? ($base . "/panel/venues/create") : "";

        return JsonResponse::createResponse([
            "success" => true,
            "card" => $customer,
            "needs_profile" => $needsProfile,
            "next_title" => $nextTitle,
            "next_url" => $nextUrl
        ]);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 500);
    }
});

$router->run();
