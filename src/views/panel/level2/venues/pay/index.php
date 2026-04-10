<?php

use App\Repositories\PaymentsVenuesRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse; 

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $venueId = $_GET["id"] ?? null;

    if (!$venueId || $user->getLevel() != 2) {
        LocationUtils::redirectInternal("panel/venues/home");
    }

    $venueRepository = new VenueRepository();
    $venue = $venueRepository->getOne(["id" => $venueId]);

    if (!$venue || $venue->user_id != $user->getId()) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venue" => $venue,
        "amount" => $_ENV["VENUE_PAYMENT_AMOUNT"],
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $venueId = $_POST["venue_id"] ?? null;

    if (!$venueId || $user->getLevel() != 2) {
        LocationUtils::redirectInternal("panel/venues/home");
    }

    $venueRepository = new VenueRepository();
    $venue = $venueRepository->getOne(["id" => $venueId]);

    if (!$venue || $venue->user_id != $user->getId()) {
        MessageUtil::setMessage("Invalid venue.");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    $cardRepo = new UserCardsRepository();
    $mainCard = $cardRepo->getOne([
        "id_user" => $user->getId(),
        "main_card" => "yes"
    ]);

    if (!$mainCard) {
        MessageUtil::setMessage("You must add a card before paying.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $stripeService = new StripeService();
    $amount = floatval($_ENV["VENUE_PAYMENT_AMOUNT"]);

    $success = $stripeService->createChargeV1($mainCard->token, $amount);

    if (!$success) {
        MessageUtil::setMessage("Payment failed.");
        LocationUtils::redirectInternal("panel/venues/pay?id=" . $venueId);
    }

    $paymentRepo = new PaymentsVenuesRepository();
    $paymentRepo->add([
        "id_venues" => $venueId,
        "renewal" => date("Y-m-d", strtotime("+1 year")),
        "active" => "yes"
    ]);

    MessageUtil::setMessage("Venue successfully paid.");
    LocationUtils::redirectInternal("panel/venues/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
