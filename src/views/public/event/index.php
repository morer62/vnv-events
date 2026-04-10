<?php

use App\Repositories\EventsRepository;
use App\Repositories\EventGuestsRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $slug = $_GET["slug"] ?? null;
    $token = $_GET["token"] ?? null;
    
    if (!$slug) {
        LocationUtils::redirectInternal("/404");
    }
    
    $eventsRepo = new EventsRepository();
    $event = $eventsRepo->getBySlug($slug);
    
    if (!$event) {
        LocationUtils::redirectInternal("/404");
    }
    
    if ($event->status !== 'active') {
        LocationUtils::redirectInternal("/404");
    }
    
    $guest = null;
    $rsvpDeadlinePassed = false;
    $eventDatePassed = false;
    
    // Check if event date has passed
    if ($event->event_date) {
        $eventDate = new \DateTime($event->event_date);
        $today = new \DateTime();
        $today->setTime(0, 0, 0); // Start of today
        
        if ($eventDate < $today) {
            $eventDatePassed = true;
        }
    }
    
    if ($token) {
        $guestsRepo = new EventGuestsRepository();
        $guest = $guestsRepo->getByToken($token);
        
        if ($guest && $guest->id_event == $event->id) {
            $guestsRepo->markInvitationOpened($guest->id);
            
            // Check if RSVP deadline has passed
            if ($event->rsvp_deadline && !$eventDatePassed) {
                $deadlineDate = new \DateTime($event->rsvp_deadline);
                $today = new \DateTime();
                $today->setTime(23, 59, 59); // End of today
                
                if ($deadlineDate < $today) {
                    $rsvpDeadlinePassed = true;
                }
            }
        }
    }
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "event" => $event,
        "guest" => $guest,
        "token" => $token,
        "rsvpDeadlinePassed" => $rsvpDeadlinePassed,
        "eventDatePassed" => $eventDatePassed
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
    
    // Check if event date has passed
    $eventDatePassed = false;
    if ($event->event_date) {
        $eventDate = new \DateTime($event->event_date);
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        
        if ($eventDate < $today) {
            $eventDatePassed = true;
        }
    }
    
    // Check if RSVP deadline has passed
    $rsvpDeadlinePassed = false;
    if ($event->rsvp_deadline && !$eventDatePassed) {
        $deadlineDate = new \DateTime($event->rsvp_deadline);
        $today = new \DateTime();
        $today->setTime(23, 59, 59);
        
        if ($deadlineDate < $today) {
            $rsvpDeadlinePassed = true;
        }
    }
    
    if ($eventDatePassed && $guest->rsvp_status === 'pending') {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "event" => $event,
            "guest" => $guest,
            "token" => $token,
            "rsvpDeadlinePassed" => false,
            "eventDatePassed" => true,
            "errorMessage" => "This event has already passed. RSVP is no longer available."
        ]);
    }
    
    if ($rsvpDeadlinePassed && $guest->rsvp_status === 'pending') {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "event" => $event,
            "guest" => $guest,
            "token" => $token,
            "rsvpDeadlinePassed" => true,
            "eventDatePassed" => false,
            "errorMessage" => "The RSVP deadline has passed. Please contact the event organizer if you need to update your response."
        ]);
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
