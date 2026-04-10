<?php

use App\Repositories\EventsRepository;
use App\Repositories\EventGuestsRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $urlParts = explode('/', trim($_GET['url'] ?? '', '/'));
    $slug = $urlParts[1] ?? null;
    $token = $_GET["token"] ?? null;
    
    error_log("DEBUG Event: URL parts = " . print_r($urlParts, true));
    error_log("DEBUG Event: Extracted slug = " . $slug);
    
    if (!$slug) {
        error_log("DEBUG Event: No slug found, redirecting to 404");
        LocationUtils::redirectInternal("/404");
    }
    
    $eventsRepo = new EventsRepository();
    $event = $eventsRepo->getBySlug($slug);
    
    error_log("DEBUG Event: Event found = " . ($event ? "YES (ID: {$event->id})" : "NO"));
    
    if (!$event) {
        error_log("DEBUG Event: Event not found for slug: {$slug}");
        LocationUtils::redirectInternal("/404");
    }
    
    if ($event->status !== 'active') {
        error_log("DEBUG Event: Event status is {$event->status}, not active");
        LocationUtils::redirectInternal("/404");
    }
    
    $guest = null;
    if ($token) {
        $guestsRepo = new EventGuestsRepository();
        $guest = $guestsRepo->getByToken($token);
        
        if ($guest && $guest->id_event == $event->id) {
            $guestsRepo->markInvitationOpened($guest->id);
        }
    }
    
    error_log("DEBUG Event: About to render template");
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "event" => $event,
        "guest" => $guest,
        "token" => $token
    ]);
});

$router->post(function () {
    $slug = $_POST["slug"] ?? null;
    $token = $_POST["token"] ?? null;
    
    if (!$slug || !$token) {
        LocationUtils::redirectInternal("/404");
    }
    
    $eventsRepo = new EventsRepository();
    $event = $eventsRepo->getBySlug($slug);
    
    if (!$event) {
        LocationUtils::redirectInternal("/404");
    }
    
    $guestsRepo = new EventGuestsRepository();
    $guest = $guestsRepo->getByToken($token);
    
    if (!$guest || $guest->id_event != $event->id) {
        LocationUtils::redirectInternal("/404");
    }
    
    $rsvpStatus = $_POST["rsvp_status"] ?? 'pending';
    $plusOnes = intval($_POST["plus_ones"] ?? 0);
    $plusOnesNames = [];
    
    if ($plusOnes > 0) {
        for ($i = 1; $i <= $plusOnes; $i++) {
            $name = trim($_POST["plus_one_name_$i"] ?? '');
            if (!empty($name)) {
                $plusOnesNames[] = $name;
            }
        }
    }
    
    $updateData = [
        "rsvp_status" => $rsvpStatus,
        "plus_ones" => $plusOnes,
        "plus_ones_names" => json_encode($plusOnesNames),
        "meal_preference" => $_POST["meal_preference"] ?? null,
        "dietary_restrictions" => $_POST["dietary_restrictions"] ?? null,
        "special_notes" => $_POST["special_notes"] ?? null
    ];
    
    if ($rsvpStatus === 'declined') {
        $updateData["decline_reason"] = $_POST["decline_reason"] ?? null;
    }
    
    $guestsRepo->updateRSVP($guest->id, $updateData);
    
    $successMessage = $rsvpStatus === 'confirmed' 
        ? "Thank you for confirming! We look forward to seeing you." 
        : "Thank you for your response.";
    
    return TemplateResponse::render(__DIR__ . "/success.twig", [
        "event" => $event,
        "guest" => $guest,
        "message" => $successMessage,
        "rsvpStatus" => $rsvpStatus
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

