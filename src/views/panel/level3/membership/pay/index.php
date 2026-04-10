<?php

use App\Repositories\UserCardsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\PlatformDetector;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    if (PlatformDetector::isMobileApp()) {
        return TemplateResponse::renderInTemplates("web-only-feature.twig", [
            "title" => "Membership Renewal",
            "message" => "Your membership renewal and payment details can be managed on the platform where you originally signed up.",
            "showWebLink" => false,
            "icon" => "💎",
            "websiteUrl" => $_ENV["APP_URL"] ?? "https://ophyra.com"
        ]);
    }

    $cardRepo = new UserCardsRepository();
    $mainCard = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => "yes"
    ]);

    if (!$mainCard) {
        MessageUtil::setMessage("You must add a payment method before selecting a membership.");
        LocationUtils::redirectInternal("panel/cards");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "monthly_price" => $_ENV["MEMBERSHIP_PLANNER_HUB_MONTH"],
        "annual_price" => $_ENV["MEMBERSHIP_PLANNER_HUB_ANUAL"],
        "isVenue" => $user->getLevel() === 2,
        "isVendor" => $user->getLevel() === 3,
        "venuePrice" => $_ENV["VENUE_PAYMENT_AMOUNT"],
        "venuePriceDiscount" => $_ENV["VENUE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"],
        "vendorPrice" => $_ENV["SERVICE_PAYMENT_AMOUNT"],
        "vendorPriceDiscount" => $_ENV["SERVICE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"],
        "websiteUrl" => $_ENV["APP_URL"] ?? "https://ophyra.com"
    ]);

});

$router->post(function () {
    $user = LoginService::getSession();
    
    if (PlatformDetector::isMobileApp()) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Membership payments are not available in the mobile app. Please visit our website."
        ], 403);
    }
    
    $cardRepo = new UserCardsRepository();
    $userRepo = new UserRepository();
    $stripeService = new StripeService();

    $plan = $_POST["plan"] ?? "";

    $mainCard = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => "yes"
    ]);

    if (!$mainCard) {
        MessageUtil::setMessage("No payment method available.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $amount = match ($plan) {
        "monthly" => floatval($_ENV["MEMBERSHIP_PLANNER_HUB_MONTH"]),
         "annual" => floatval($_ENV["MEMBERSHIP_PLANNER_HUB_ANUAL"]),
        default => null
    };

    if (is_null($amount)) {
        MessageUtil::setMessage("Invalid membership plan.");
        LocationUtils::redirectInternal("panel/membership");
    }

    $paymentSuccess = $stripeService->createChargeV1($mainCard->token, $amount);

    if (!$paymentSuccess) {
        MessageUtil::setMessage("Payment failed. Please try again.");
        LocationUtils::redirectInternal("panel/membership");
    }

    $newDate = new DateTime();
    $newDate->add(new DateInterval($plan === "annual" ? "P1Y" : "P1M"));

    $userRepo->updateMembershipAndRegisterPayment($user->getId(), $newDate->format("Y-m-d"), $amount );

    // Recargar el usuario desde la base de datos para actualizar la sesión con los datos más recientes
    $updatedUser = $userRepo->getOneWithoutOwnership(['id' => $user->getId()]);
    if ($updatedUser) {
        LoginService::authenticateFromUserDbo($updatedUser);
    }

    MessageUtil::setMessage("Membership activated successfully.");
    LocationUtils::redirectInternal("panel/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
