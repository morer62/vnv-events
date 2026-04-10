<?php

use App\Repositories\Connection;
use App\Repositories\UserRepository;
use App\Repositories\VenueRepository;
use App\Repositories\ServiceRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET["id"] ?? 0;
    $db = new Connection();

    $db->query("SELECT * FROM payments_all WHERE id = :id LIMIT 1");
    $db->bind(":id", $id);
    $payment = $db->fetchOne();

    if (!$payment) {
        return TemplateResponse::render(__DIR__ . "/not-found.twig");
    }

    // Datos del usuario
    $userRepo = new UserRepository();
    $user = $userRepo->getOne(["id" => $payment->user_id]);

    // Obtener item relacionado
    $itemName = '—';
    if ($payment->concept === "Venue") {
        $venueRepo = new VenueRepository();
        $item = $venueRepo->getOne(["id" => $payment->concept_id]);
        $itemName = $item ? $item->name : '—';
    } elseif ($payment->concept === "Service") {
        $serviceRepo = new ServiceRepository();
        $item = $serviceRepo->getOne(["id" => $payment->concept_id]);
        $itemName = $item ? $item->name : '—';
    } elseif ($payment->concept === "Membership") {
        $itemName = "Membership Plan #" . $payment->id_membership_plan;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payment" => $payment,
        "user" => $user,
        "item" => $itemName
    ]);
});

$router->run();
