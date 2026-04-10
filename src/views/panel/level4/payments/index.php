<?php

use App\Services\LoginService;
use App\Repositories\PaymentsVenuesRepository;
use App\Repositories\VenueRepository;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function (): string {
    $user = LoginService::getSession();
    $venueRepo = new VenueRepository();
    $paymentRepo = new PaymentsVenuesRepository();

    $venue = $venueRepo->getOne([
        "user_id" => $user->getId()
    ]);

    if (!$venue) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "payments" => []
        ]);
    }

    $payments = $paymentRepo->getAllBy([
        "id_venues" => $venue->id
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $payments
    ]);
});

$router->run();
