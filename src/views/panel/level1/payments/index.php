<?php

use App\Repositories\UserRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $type = $_GET['type'] ?? '';
    $email = $_GET['email'] ?? '';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $userRepo = new UserRepository();
    $db = new Connection();

    $query = "
        SELECT pa.*, u.name, u.email, u.phone
        FROM payments_all pa
        JOIN users u ON pa.user_id = u.id
        WHERE 1=1
    ";

    $params = [];

     if (in_array($type, ['services', 'venues', 'memberships', 'events'])) {
        $query .= " AND pa.concept = :concept";
        $params[":concept"] = ucfirst(rtrim($type, 's')); // convierte "services" en "Service", "events" en "Event"
    }

    if (!empty($email)) {
        $query .= " AND u.email LIKE :email";
        $params[":email"] = "%$email%";
    }

    if (!empty($from)) {
        $query .= " AND pa.payment_date >= :from";
        $params[":from"] = $from;
    }

    if (!empty($to)) {
        $query .= " AND pa.payment_date <= :to";
        $params[":to"] = $to;
    }

    $query .= " ORDER BY pa.payment_date DESC";

    $db->query($query);
    foreach ($params as $key => $val) {
        $db->bind($key, $val);
    }

    $results = $db->fetchAll();

    // Resuelve nombres de servicios, venues o eventos si aplica
    $venueRepo = new \App\Repositories\VenueRepository();
    $serviceRepo = new \App\Repositories\ServiceRepository();
    $eventsRepo = new \App\Repositories\EventsRepository();

    foreach ($results as &$row) {
        $concept = $row->concept;
        $conceptId = $row->concept_id;

        if ($concept === 'Venue') {
            $venue = $venueRepo->getOne(["id" => $conceptId]);
            $row->item = $venue ? $venue->name : '—';
        } elseif ($concept === 'Service') {
            $service = $serviceRepo->getOne(["id" => $conceptId]);
            $row->item = $service ? $service->name : '—';
        } elseif ($concept === 'Event') {
            try {
                $event = $eventsRepo->getOne(["id" => $conceptId]);
                $row->item = $event ? $event->event_name : 'Event #' . $conceptId;
            } catch (\Exception $e) {
                $row->item = 'Event #' . $conceptId;
            }
        } elseif ($concept === 'Membership') {
            $row->item = "Membership Plan";
        } else {
            $row->item = $concept;
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $results,
        "type" => $type,
        "email" => $email,
        "from" => $from,
        "to" => $to,
    ]);
});

$router->run();
