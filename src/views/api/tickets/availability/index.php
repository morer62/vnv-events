<?php

use App\Repositories\TicketInventoryRepository;
use App\Repositories\TicketTypesRepository;

$eventId = $_GET['event_id'] ?? null;

if (!$eventId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit;
}

try {
    $ticketTypesRepo = new TicketTypesRepository();
    $inventoryRepo = new TicketInventoryRepository();
    
    $ticketsConfigRepo = new \App\Repositories\VenueEventsTicketsRepository();
    $ticketsConfig = $ticketsConfigRepo->getOne(['id_venue_event' => $eventId]);
    
    if (!$ticketsConfig) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No tickets configured for this event']);
        exit;
    }
    
    $salesStagesRepo = new \App\Repositories\TicketSalesStagesRepository();
    $existingStages = $salesStagesRepo->getAllBy(['id_venue_event_tickets' => $ticketsConfig->id]);
    
    if (empty($existingStages)) {
        $salesStagesRepo->add([
            'id_venue_event_tickets' => $ticketsConfig->id,
            'name' => 'General Sale',
            'description' => 'General ticket sale',
            'start_date' => date('Y-m-d H:i:s'),
            'end_date' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'discount_percentage' => 0.00,
            'is_active' => 1,
            'sort_order' => 1
        ]);
    }
    
    $ticketTypes = $ticketTypesRepo->getAllBy(['id_venue_event_tickets' => $ticketsConfig->id]);
    $availability = [];
    
    foreach ($ticketTypes as $ticketType) {
        $inventoryRepo->initializeInventoryForTicketType($ticketType->id, 100);
        $available = $inventoryRepo->getAvailableQuantity($ticketType->id);
        
        $availability[$ticketType->id] = [
            'name' => $ticketType->name,
            'price' => $ticketType->price,
            'available' => $available,
            'description' => $ticketType->description
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $availability]);
    exit;
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error checking availability: ' . $e->getMessage()]);
    exit;
}
