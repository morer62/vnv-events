<?php

use App\Services\LoginService;
use App\Repositories\Connection;
use App\Repositories\VenueRepository;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function (): string {
    $user = LoginService::getSession();
    $venueRepo = new VenueRepository();
    $db = new Connection();

    // 1. Obtener venues del usuario
    $venues = $venueRepo->getAllBy([
        "user_id" => $user->getId()
    ]);

    $venueIds = array_map(fn($v) => $v->id, $venues);
    $venuePayments = [];

    if (!empty($venueIds)) {
        $placeholders = implode(",", array_fill(0, count($venueIds), "?"));
        $db->query("SELECT * FROM payments_all WHERE concept = 'Venue' AND concept_id IN ($placeholders)");
        foreach ($venueIds as $i => $id) {
            $db->bind($i + 1, $id);
        }
        $venuePayments = $db->fetchAll();
    }

    // 2. Pagos de membresía
    $db->query("SELECT * FROM payments_all WHERE concept = 'Membership' AND user_id = ?");
    $db->bind(1, $user->getId());
    $membershipPayments = $db->fetchAll();

    // 3. Pagos de servicios (ZIP codes)
    $db->query("SELECT * FROM payments_all WHERE concept = 'Service' AND user_id = ?");
    $db->bind(1, $user->getId());
    $servicePayments = $db->fetchAll();

    // 4. Unir y ordenar
    $payments = array_merge($venuePayments, $membershipPayments, $servicePayments);
    usort($payments, fn($a, $b) => strtotime($b->payment_date) <=> strtotime($a->payment_date));


    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $payments
    ]);
});


$router->run();
