<?php

use App\Utils\Router;
use App\Utils\JsonResponse;
use App\Repositories\VenueEventsRepository;
use App\Repositories\VenueEventsTicketsRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\TicketSalesStagesRepository;
use App\Repositories\TicketInventoryRepository;

$router = new Router();

$router->get(function () {
    $eventId = $_GET['event_id'] ?? null;
    
    if (!$eventId) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Event ID is required'
        ])->handle();
    }
    
    $venueEventsRepo = new VenueEventsRepository();
    $event = $venueEventsRepo->getOne(['id' => $eventId]);
    
    if (!$event) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Event not found'
        ])->handle();
    }
    
    // Check if event has ended
    $now = new DateTime();
    $eventEndDate = new DateTime($event->end_date);
    
    if ($now > $eventEndDate) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'This event has already ended',
            'event_ended' => true
        ])->handle();
    }
    
    $ticketsRepo = new VenueEventsTicketsRepository();
    $ticketTypesRepo = new TicketTypesRepository();
    $salesStagesRepo = new TicketSalesStagesRepository();
    $inventoryRepo = new TicketInventoryRepository();
    
    $ticketsConfig = $ticketsRepo->getByVenueEvent($eventId);
    
    if (!$ticketsConfig || !$ticketsConfig->ticket_sales_enabled) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Ticket sales are not enabled for this event'
        ])->handle();
    }
    
    $ticketTypes = $ticketTypesRepo->getByEventTickets($ticketsConfig->id);
    $currentStage = $salesStagesRepo->getCurrentStage($ticketsConfig->id);
    
    $ticketsWithPricing = [];
    
    foreach ($ticketTypes as $ticketType) {
        $originalPrice = $ticketType->price;
        $discountedPrice = $originalPrice;
        $discountPercentage = 0;
        $stageName = 'General Sale';
        
        if ($currentStage && $currentStage->discount_percentage > 0) {
            $discountPercentage = $currentStage->discount_percentage;
            $discountedPrice = $originalPrice * (1 - $discountPercentage / 100);
            $stageName = $currentStage->name;
        }
        
        // Get available quantity
        $availableQuantity = 0;
        if ($currentStage) {
            $inventory = $inventoryRepo->getOne([
                'id_ticket_type' => $ticketType->id,
                'id_sales_stage' => $currentStage->id
            ]);
            $availableQuantity = $inventory ? $inventory->available_quantity : 0;
        }
        
        $ticketsWithPricing[] = [
            'id' => $ticketType->id,
            'name' => $ticketType->name,
            'description' => $ticketType->description,
            'original_price' => $originalPrice,
            'discounted_price' => round($discountedPrice, 2),
            'discount_percentage' => $discountPercentage,
            'stage_name' => $stageName,
            'available_quantity' => $availableQuantity,
            'stage_id' => $currentStage ? $currentStage->id : null
        ];
    }
    
    return JsonResponse::createResponse([
        'success' => true,
        'event' => [
            'id' => $event->id,
            'name' => $event->name,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'location' => $event->location ?? $event->venue_location ?? null
        ],
        'current_stage' => $currentStage ? [
            'id' => $currentStage->id,
            'name' => $currentStage->name,
            'discount_percentage' => $currentStage->discount_percentage,
            'start_date' => $currentStage->start_date,
            'end_date' => $currentStage->end_date
        ] : null,
        'tickets' => $ticketsWithPricing
    ])->handle();
});

$router->run();
