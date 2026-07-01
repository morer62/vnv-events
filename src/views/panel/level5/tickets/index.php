<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Repositories\TicketSalesRepository;
use App\Repositories\VenueEventsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

    $router->get(function () {
        $user = LoginService::getSession();
        
        if (!$user) {
            LocationUtils::redirectInternal("login");
        }

        $ticketSalesRepo = new TicketSalesRepository();
        $venueEventsRepo = new VenueEventsRepository();

        try {
            $userTickets = $ticketSalesRepo->getByBuyerEmail($user->getEmail());
        } catch (\Throwable $e) {
            error_log("Level 5 tickets failed loading user tickets: " . $e->getMessage());
            $userTickets = [];
        }

        try {
            $activeEvents = $venueEventsRepo->getActiveWithTicketConfig();
        } catch (\Throwable $e) {
            error_log("Level 5 tickets failed loading active events: " . $e->getMessage());
            $activeEvents = [];
        }

        $enrichedTickets = [];
        foreach ($userTickets as $ticket) {
            $event = $venueEventsRepo->getOne(['id' => $ticket->venue_event_id]);
            $ticket->event_name = $event ? $event->name : 'Event not found';
            $ticket->event_date = $event ? $event->start_date : null;
            $ticket->event_location = $event ? ($event->location ?? $event->venue_location ?? null) : null;
            
            if ($ticket->ticket_codes) {
                $ticket->ticket_codes = json_decode($ticket->ticket_codes, true);
            }
            if ($ticket->qr_codes) {
                $ticket->qr_codes = json_decode($ticket->qr_codes, true);
            }
            
            $enrichedTickets[] = $ticket;
        }

        $ticketsByEvent = [];
        $activeTickets = [];
        $usedTickets = [];
        
        foreach ($enrichedTickets as $ticket) {
            $eventId = $ticket->venue_event_id;
            if (!isset($ticketsByEvent[$eventId])) {
                $ticketsByEvent[$eventId] = [
                    'event' => [
                        'id' => $eventId,
                        'name' => $ticket->event_name,
                        'date' => $ticket->event_date,
                        'location' => $ticket->event_location
                    ],
                    'tickets' => []
                ];
            }
            $ticketsByEvent[$eventId]['tickets'][] = $ticket;
            
            // Separate active and used tickets
            if ($ticket->updated_at !== $ticket->created_at) {
                $usedTickets[] = $ticket;
            } else {
                $activeTickets[] = $ticket;
            }
        }

        $templateData = [
            "user" => $user,
            "ticketsByEvent" => $ticketsByEvent,
            "activeTickets" => $activeTickets,
            "usedTickets" => $usedTickets,
            "totalTickets" => count($enrichedTickets),
            "activeCount" => count($activeTickets),
            "usedCount" => count($usedTickets),
            "activeEvents" => $activeEvents
        ];
        
        return TemplateResponse::render(__DIR__ . "/index.twig", $templateData);
    });

$router->run();
