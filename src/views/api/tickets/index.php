<?php

use App\Repositories\VenueEventsTicketsRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\TicketSalesStagesRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;

Cors::handle();

try {
    // Get event ID from GET parameter
    $eventId = $_GET['event_id'] ?? null;

    if (!$eventId) {
        echo json_encode([
            'success' => false,
            'message' => 'Event ID is required'
        ]);
        exit;
    }

    error_log("DEBUG: Loading tickets for event ID: " . $eventId);

    $ticketsRepo = new VenueEventsTicketsRepository();
    $ticketTypesRepo = new TicketTypesRepository();
    $salesStagesRepo = new TicketSalesStagesRepository();

    $ticketsConfig = $ticketsRepo->getByVenueEvent($eventId);

    if (!$ticketsConfig || !$ticketsConfig->ticket_sales_enabled) {
        echo json_encode([
            'success' => false,
            'message' => 'Ticket sales are not enabled for this event'
        ]);
        exit;
    }

    $ticketTypes = $ticketTypesRepo->getByEventTickets($ticketsConfig->id);
    $currentStage = $salesStagesRepo->getCurrentStage($eventId);

    echo json_encode([
        'success' => true,
        'ticketTypes' => $ticketTypes,
        'currentStage' => $currentStage
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading ticket data: ' . $e->getMessage()
    ]);
}
