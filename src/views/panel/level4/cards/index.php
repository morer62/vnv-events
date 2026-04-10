<?php

use App\Repositories\UserCardsRepository;
use App\Repositories\UserBillingInfoRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    // Validar si el usuario tiene info de billing
    $billingRepo = new UserBillingInfoRepository();
    $billing = $billingRepo->getByUserId($user->getId());

    if (!$billing) {
        LocationUtils::redirectInternal("panel/billing");
    }

    $cardRepo = new UserCardsRepository();
    $cards = $cardRepo->getByUserId($user->getId());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "stripe_key" => $_ENV["STRIPE_PUBLIC"],
        "cards" => $cards
    ]);
});

$router->post(function () {
    try {
        $user = LoginService::getSession();
        $repo = new UserCardsRepository();

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

        $result = $repo->add([
            "id_user" => $user->getId(),
            "brand" => $cardInfo["brand"],
            "last4" => $cardInfo["last4"],
            "exp" => $cardInfo["exp"],
            "token" => $customer,
            "main_card" => $main,
            "billing_zip" => "NA"
        ]);

        if (!$result) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Failed to save card. Please try again."
            ], 500);
        }

        return JsonResponse::createResponse([
            "success" => true,
            "card" => $customer
        ]);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 500);
    }
});

$router->run();