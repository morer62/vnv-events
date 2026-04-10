<?php

use App\Repositories\PaymentsServicesRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse; 

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $ServiceId = $_GET["id"] ?? null;

    if (!$ServiceId || $user->getLevel() != 2) {
        MessageUtil::setMessage("Not enough permissions to perform this action");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $ServiceRepository = new ServiceRepository();
    $Service = $ServiceRepository->getOne(["id" => $ServiceId]);

    if (!$Service || $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/Services/home");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "Service" => $Service,
        "amount" => $_ENV["Service_PAYMENT_AMOUNT"],
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $ServiceId = $_POST["Service_id"] ?? null;

    if (!$ServiceId || $user->getLevel() != 2) {
        LocationUtils::redirectInternal("panel/Services/home");
    }

    $ServiceRepository = new ServiceRepository();
    $Service = $ServiceRepository->getOne(["id" => $ServiceId]);

    if (!$Service || $Service->user_id != $user->getId()) {
        MessageUtil::setMessage("Invalid Service.");
        LocationUtils::redirectInternal("panel/Services/home");
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
    $amount = floatval($_ENV["Service_PAYMENT_AMOUNT"]);

    $success = $stripeService->createChargeV1($mainCard->token, $amount);

    if (!$success) {
        MessageUtil::setMessage("Payment failed.");
        LocationUtils::redirectInternal("panel/Services/pay?id=" . $ServiceId);
    }

    $paymentRepo = new PaymentsServicesRepository();
    $paymentRepo->add([
        "id_Services" => $ServiceId,
        "renewal" => date("Y-m-d", strtotime("+1 year")),
        "active" => "yes"
    ]);

    MessageUtil::setMessage("Service successfully paid.");
    LocationUtils::redirectInternal("panel/Services/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
