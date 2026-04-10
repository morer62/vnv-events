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
            "message" => "Membership payments are only available on the website.",
            "showWebLink" => false,
            "icon" => "💎",
            "websiteUrl" => $_ENV["APP_URL"]
        ]);
    }

    $cardRepo = new UserCardsRepository();
    $mainCard = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => "yes"
    ]);

    if (!$mainCard) {
        MessageUtil::setMessage("You must add a payment method first.");
        LocationUtils::redirectInternal("panel/cards");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "monthly_price"   => $_ENV["MEMBERSHIP_PLAN_MONTHLY"],
        "quarterly_price" => $_ENV["MEMBERSHIP_PLAN_QUARTERLY"],
        "annual_price"    => $_ENV["MEMBERSHIP_PLAN_ANNUAL"],
    ]);
});


$router->post(function () {
    $user = LoginService::getSession();

    $cardRepo = new UserCardsRepository();
    $stripe   = new StripeService();
    $userRepo = new UserRepository();

    $plan = $_POST["plan"] ?? null;

    $prices = [
        "monthly"   => floatval($_ENV["MEMBERSHIP_PLAN_MONTHLY"]),
        "quarterly" => floatval($_ENV["MEMBERSHIP_PLAN_QUARTERLY"]),
        "annual"    => floatval($_ENV["MEMBERSHIP_PLAN_ANNUAL"]),
    ];

    if (!isset($prices[$plan])) {
        MessageUtil::setMessage("Invalid membership plan.");
        LocationUtils::redirectInternal("panel/membership");
    }

    $amount = $prices[$plan];

    $mainCard = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => "yes"
    ]);

    if (!$mainCard) {
        MessageUtil::setMessage("You need a payment method.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $success = $stripe->createChargeV1($mainCard->token, $amount);

    if (!$success) {
        MessageUtil::setMessage("Payment failed.");
        LocationUtils::redirectInternal("panel/membership");
    }

    $newDate = new DateTime();

    if ($plan === "monthly") {
        $newDate->add(new DateInterval("P1M"));
    } elseif ($plan === "quarterly") {
        $newDate->add(new DateInterval("P3M"));
    } else {
        $newDate->add(new DateInterval("P1Y"));
    }

    $userRepo->updateMembershipAndRegisterPayment($user->getId(), $newDate->format("Y-m-d"), $amount);

    // Recargar el usuario desde la base de datos para actualizar la sesión con los datos más recientes
    $updatedUser = $userRepo->getOneWithoutOwnership(['id' => $user->getId()]);
    if ($updatedUser) {
        LoginService::authenticateFromUserDbo($updatedUser);
    }

    MessageUtil::setMessage("Membership activated successfully.");
    LocationUtils::redirectInternal("panel/home");
});

$router->run();
