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

    $userRepo = new UserRepository();
    $user = $userRepo->getOne(["id" => $payment->user_id]);

    $item = null;
    $itemLabel = '—';

    if ($payment->concept === "Venue") {
        $repo = new VenueRepository();
        $item = $repo->getOne(["id" => $payment->concept_id]);
        $itemLabel = $item ? $item->name : '—';
    } elseif ($payment->concept === "Service") {
        $repo = new ServiceRepository();
        $item = $repo->getOne(["id" => $payment->concept_id]);
        $itemLabel = $item ? $item->name : '—';
    } elseif ($payment->concept === "Membership") {
        $itemLabel = "Membership Plan #" . $payment->id_membership_plan;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payment" => $payment,
        "user" => $user,
        "item" => $itemLabel
    ]);
});

$router->run();
