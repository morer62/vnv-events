<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Repositories\VenueEventsTicketsRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\TicketSalesStagesRepository;
use App\Repositories\TicketInventoryRepository;
use App\Repositories\VenueEventsRepository;
use App\Repositories\StripeAccountsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $venueEventId = $_GET['event_id'] ?? null;
    
    if (!$venueEventId) {
        MessageUtil::setMessage("Event ID is required.");
        LocationUtils::redirectInternal("panel/events/home");
    }

    $stripeRepo = new StripeAccountsRepository();
    $stripeAccount = $stripeRepo->getByUser($user->getId());
    
    if (!$stripeAccount || !$stripeAccount->stripe_account_id || !$stripeAccount->is_verified) {
        MessageUtil::setMessage("⚠️ You need to connect and verify your Stripe account before managing tickets. This is required to process payments.", "warning");
        LocationUtils::redirectInternal("panel/planner-hub/management/payments");
    }

    $venueEventsRepo = new VenueEventsRepository();
    $venueEvent = $venueEventsRepo->getOne([
        'id' => $venueEventId
    ]);


    if (!$venueEvent) {
        MessageUtil::setMessage("Event not found.");
        LocationUtils::redirectInternal("panel/events/home");
    }

    $ticketsRepo = new VenueEventsTicketsRepository();
    $ticketTypesRepo = new TicketTypesRepository();
    $salesStagesRepo = new TicketSalesStagesRepository();
    $inventoryRepo = new TicketInventoryRepository();

    $ticketsConfig = $ticketsRepo->getByVenueEvent($venueEventId);
    
    if (!$ticketsConfig) {
        $ticketsConfigId = $ticketsRepo->createForVenueEvent($venueEventId, [
            'event_type' => 'physical',
            'ticket_sales_enabled' => 0,
            'commission_percentage' => 5.00,
            'stripe_fee_percentage' => 2.90
        ]);
        $ticketsConfig = $ticketsRepo->getOne(['id' => $ticketsConfigId]);
    }

    $ticketTypes = $ticketTypesRepo->getByEventTickets($ticketsConfig->id);
    $salesStages = $salesStagesRepo->getByEventTickets($ticketsConfig->id);
    
    // Limpiar etapas duplicadas de "General Sale"
    $generalSaleStages = array_filter($salesStages, function($stage) {
        return $stage->name === 'General Sale';
    });
    
        if (count($generalSaleStages) > 1) {
            $firstGeneralSale = reset($generalSaleStages);
            $stagesToDelete = array_slice($generalSaleStages, 1);
            
            foreach ($stagesToDelete as $stage) {
                $salesStagesRepo->delete(['id' => $stage->id]);
            }
            
            $salesStages = $salesStagesRepo->getByEventTickets($ticketsConfig->id);
        }
    
    $inventoryMatrix = $inventoryRepo->getInventoryMatrix($ticketsConfig->id);
    $salesSummary = $ticketsRepo->getSalesSummary($ticketsConfig->id);


    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venueEvent" => $venueEvent,
        "ticketsConfig" => $ticketsConfig,
        "ticketTypes" => $ticketTypes,
        "salesStages" => $salesStages,
        "inventoryMatrix" => $inventoryMatrix,
        "salesSummary" => $salesSummary,
        "step" => $_GET['step'] ?? 1
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $action = $_POST['action'] ?? '';
    $venueEventId = $_POST['venue_event_id'] ?? null;

    if (!$venueEventId) {
        MessageUtil::setMessage("Event ID is required.");
        LocationUtils::redirectInternal("panel/events/home");
    }

    $stripeRepo = new StripeAccountsRepository();
    $stripeAccount = $stripeRepo->getByUser($user->getId());
    
    if (!$stripeAccount || !$stripeAccount->stripe_account_id || !$stripeAccount->is_verified) {
        MessageUtil::setMessage("⚠️ You need to connect and verify your Stripe account before managing tickets. This is required to process payments.", "warning");
        LocationUtils::redirectInternal("panel/planner-hub/management/payments");
    }

    $ticketsRepo = new VenueEventsTicketsRepository();
    $ticketTypesRepo = new TicketTypesRepository();
    $salesStagesRepo = new TicketSalesStagesRepository();
    $inventoryRepo = new TicketInventoryRepository();

    $ticketsConfig = $ticketsRepo->getByVenueEvent($venueEventId);
    
    if (!$ticketsConfig) {
        MessageUtil::setMessage("Tickets configuration not found.");
        LocationUtils::redirectInternal("panel/events/home");
    }

    switch ($action) {
        case 'update_config':
            $eventType = $_POST['event_type'] ?? 'physical';
            $digitalLink = $_POST['digital_link'] ?? '';
            $commissionPercentage = floatval($_POST['commission_percentage'] ?? 5.00);
            
            $ticketsRepo->update([
                'event_type' => $eventType,
                'digital_link' => $digitalLink,
                'commission_percentage' => $commissionPercentage,
                'total_commission_percentage' => $commissionPercentage + 2.90
            ], ['id' => $ticketsConfig->id]);
            
            MessageUtil::setMessage("Configuration updated successfully.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=1");
            break;

        case 'create_ticket_type':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            
            if (empty($name) || $price <= 0) {
                MessageUtil::setMessage("Name and price are required.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=2");
                break;
            }
            
            $ticketTypesRepo->createType($ticketsConfig->id, [
                'name' => $name,
                'description' => $description,
                'price' => $price
            ]);
            
            MessageUtil::setMessage("Ticket type created successfully.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=2");
            break;

        case 'update_ticket_type':
            $ticketTypeId = intval($_POST['ticket_type_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            
            if ($ticketTypeId <= 0 || empty($name) || $price <= 0) {
                MessageUtil::setMessage("Invalid data provided.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=2");
                break;
            }
            
            $ticketTypesRepo->updateType($ticketTypeId, [
                'name' => $name,
                'description' => $description,
                'price' => $price
            ]);
            
            MessageUtil::setMessage("Ticket type updated successfully.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=2");
            break;

        case 'create_sales_stage':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $discountPercentage = floatval($_POST['discount_percentage'] ?? 0);
            
            if (empty($name) || empty($startDate) || empty($endDate)) {
                MessageUtil::setMessage("Name, start date and end date are required.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
                break;
            }
            
            if (strtotime($startDate) >= strtotime($endDate)) {
                MessageUtil::setMessage("End date must be after start date.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
                break;
            }
            
            $result = $salesStagesRepo->createStage($ticketsConfig->id, [
                'name' => $name,
                'description' => $description,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'discount_percentage' => $discountPercentage
            ]);
            
            if (!$result['success']) {
                MessageUtil::setMessage($result['message'], "error");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
                break;
            }
            
            MessageUtil::setMessage("Sales stage created successfully.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
            break;

        case 'update_sales_stage':
            $stageId = intval($_POST['stage_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $discountPercentage = floatval($_POST['discount_percentage'] ?? 0);
            
            if ($stageId <= 0 || empty($name) || empty($startDate) || empty($endDate)) {
                MessageUtil::setMessage("Invalid data provided.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
                break;
            }
            
            $salesStagesRepo->updateStage($stageId, [
                'name' => $name,
                'description' => $description,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'discount_percentage' => $discountPercentage
            ]);
            
            MessageUtil::setMessage("Sales stage updated successfully.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
            break;

        case 'update_inventory':
            $inventoryData = $_POST['inventory'] ?? [];
            
            foreach ($inventoryData as $index => $item) {
                $ticketTypeId = intval($item['ticket_type_id'] ?? 0);
                $stageId = intval($item['stage_id'] ?? 0);
                $quantity = intval($item['quantity'] ?? 0);
                
                if ($ticketTypeId > 0 && $stageId > 0 && $quantity >= 0) {
                    $existing = $inventoryRepo->getOne([
                        'id_ticket_type' => $ticketTypeId,
                        'id_sales_stage' => $stageId
                    ]);
                    
                    if ($existing) {
                        $inventoryRepo->updateInventory($ticketTypeId, $stageId, $quantity);
                    } else {
                        $inventoryRepo->createInventory($ticketTypeId, $stageId, $quantity);
                    }
                }
            }
            
            MessageUtil::setMessage("Inventory updated successfully.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=4");
            break;

        case 'enable_ticket_sales':
            // Primero guardar el inventario si hay datos
            $inventoryData = $_POST['inventory'] ?? [];
            if (!empty($inventoryData)) {
                foreach ($inventoryData as $index => $item) {
                    $ticketTypeId = intval($item['ticket_type_id'] ?? 0);
                    $stageId = intval($item['stage_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    
                    if ($ticketTypeId > 0 && $stageId > 0 && $quantity >= 0) {
                        $existing = $inventoryRepo->getOne([
                            'id_ticket_type' => $ticketTypeId,
                            'id_sales_stage' => $stageId
                        ]);
                        
                        if ($existing) {
                            $inventoryRepo->updateInventory($ticketTypeId, $stageId, $quantity);
                        } else {
                            $inventoryRepo->createInventory($ticketTypeId, $stageId, $quantity);
                        }
                    }
                }
            }
            
            // Luego habilitar las ventas
            $ticketsRepo->enableTicketSales($ticketsConfig->id);
            MessageUtil::setMessage("Ticket sales enabled successfully!");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=4");
            break;

        case 'disable_ticket_sales':
            $ticketsRepo->disableTicketSales($ticketsConfig->id);
            MessageUtil::setMessage("Ticket sales disabled.");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=1");
            break;

        case 'delete_ticket_type':
            $ticketTypeId = intval($_POST['ticket_type_id'] ?? 0);
            if ($ticketTypeId > 0) {
                $ticketTypesRepo->delete(['id' => $ticketTypeId]);
                MessageUtil::setMessage("Ticket type deleted successfully.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=2");
            }
            break;

        case 'delete_sales_stage':
            $stageId = intval($_POST['stage_id'] ?? 0);
            if ($stageId > 0) {
                $salesStagesRepo->delete(['id' => $stageId]);
                MessageUtil::setMessage("Sales stage deleted successfully.");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}&step=3");
            }
            break;

        case 'validate_qr_code':
            $qrCode = trim($_POST['qr_code'] ?? '');
            if (empty($qrCode)) {
                MessageUtil::setMessage("QR code is required.", "error");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}");
                break;
            }
            
            $db = new \App\Repositories\Connection();
            $searchPattern = '%"' . $qrCode . '"%';
            $sql = "SELECT ts.*, tt.name as ticket_type_name, vet.id_venue_event as venue_event_id 
                    FROM ticket_sales ts 
                    JOIN ticket_types tt ON ts.id_ticket_type = tt.id 
                    JOIN venue_events_tickets vet ON tt.id_venue_event_tickets = vet.id 
                    WHERE ts.ticket_codes LIKE :pattern AND vet.id_venue_event = :event_id";
            
            $db->query($sql);
            $db->bind(':pattern', $searchPattern);
            $db->bind(':event_id', $venueEventId);
            $db->execute();
            $ticket = $db->fetchOne();
            
            if (!$ticket) {
                MessageUtil::setMessage("❌ Invalid QR code. Ticket not found.", "error");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}");
                break;
            }
            
            if ($ticket->updated_at !== $ticket->created_at) {
                MessageUtil::setMessage("⚠️ This ticket has already been used.", "warning");
                LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}");
                break;
            }
            
            $updateSql = "UPDATE ticket_sales SET updated_at = :updated_at WHERE id = :id";
            $db->query($updateSql);
            $db->bind(':updated_at', date('Y-m-d H:i:s'));
            $db->bind(':id', $ticket->id);
            $db->execute();
            
            MessageUtil::setMessage("✅ Ticket validated successfully! Access granted.", "success");
            LocationUtils::redirectInternal("panel/events/tickets?event_id={$venueEventId}");
            break;
    }

    LocationUtils::redirectInternal("panel/events/tickets?event_id=" . $venueEventId);
});

try {
    $router->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
