<?php

use App\Repositories\VenueEventsTicketsRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\TicketSalesStagesRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;

Cors::handle();

try {
    // Get event ID from URL parameter
    $eventId = basename($_SERVER['REQUEST_URI']);

    if (!$eventId) {
        JsonResponse::createResponse([
            'success' => false,
            'message' => 'Event ID is required'
        ]);
        exit;
    }

    $ticketsRepo = new VenueEventsTicketsRepository();
    $ticketTypesRepo = new TicketTypesRepository();
    $salesStagesRepo = new TicketSalesStagesRepository();

    $ticketsConfig = $ticketsRepo->getByVenueEvent($eventId);

    if (!$ticketsConfig || !$ticketsConfig->ticket_sales_enabled) {
        JsonResponse::createResponse([
            'success' => false,
            'message' => 'Ticket sales are not enabled for this event'
        ]);
        exit;
    }

    $ticketTypes = $ticketTypesRepo->getByVenueEvent($eventId);
    $currentStage = $salesStagesRepo->getCurrentStage($eventId);

    JsonResponse::createResponse([
        'success' => true,
        'ticketTypes' => $ticketTypes,
        'currentStage' => $currentStage
    ]);

} catch (Exception $e) {
    JsonResponse::createResponse([
        'success' => false,
        'message' => 'Error loading ticket data: ' . $e->getMessage()
    ]);
}