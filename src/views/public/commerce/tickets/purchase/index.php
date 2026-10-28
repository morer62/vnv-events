<?php

use App\Services\TicketSalesService;
use App\Services\StripeServiceV2;
use App\Repositories\StripeAccountsRepository;
use App\Utils\Response;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->post(function () {
    $eventId = $_POST['event_id'] ?? null;
    $tickets = json_decode($_POST['tickets'] ?? '{}', true);
    $buyerInfo = json_decode($_POST['buyer_info'] ?? '{}', true);
    $cardToken = $_POST['customer_token'] ?? null;
    $customerName = trim($buyerInfo['name'] ?? "");
    $customerEmail = strtolower(trim($buyerInfo['email'] ?? ""));
    
    if (!$eventId || empty($tickets) || !$cardToken || !$customerEmail) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Missing payment data"
        ]);
    }

    $user = \App\Services\LoginService::getSession();
    if (!$user) {
        LocationUtils::redirectInternal("/login");
    }

    try {
        $venueEventsRepo = new \App\Repositories\VenueEventsRepository();
        $event = $venueEventsRepo->getOne(['id' => $eventId]);
        
        if (!$event) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Event not found"
            ]);
        }

        $venueRepo = new \App\Repositories\VenueRepository();
        $venue = $venueRepo->getFullVenueDetails($event->venue_id);
        
        if (!$venue) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Venue not found"
            ]);
        }

        $accountRepo = new StripeAccountsRepository();
        $account = $accountRepo->getByUser($venue->user_id);
        
        if (!$account || empty($account->stripe_account_id)) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Owner cannot receive payments"
            ]);
        }

        // Validar disponibilidad de tickets ANTES del pago
        $ticketInventoryRepo = new \App\Repositories\TicketInventoryRepository();
        $ticketTypesRepo = new \App\Repositories\TicketTypesRepository();
        $ticketsConfigRepo = new \App\Repositories\VenueEventsTicketsRepository();
        $salesStagesRepo = new \App\Repositories\TicketSalesStagesRepository();
        $ticketsConfig = $ticketsConfigRepo->getByVenueEvent((int)$eventId);

        if (!$ticketsConfig || !$ticketsConfig->ticket_sales_enabled) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Ticket sales are not available for this event"
            ]);
        }

        $currentStage = $salesStagesRepo->getCurrentStage((int)$ticketsConfig->id);
        $discountPercentage = $currentStage ? max(0.0, min(100.0, (float)$currentStage->discount_percentage)) : 0.0;
        $totalAmount = 0.0;
        
        foreach ($tickets as $ticketTypeId => $quantity) {
            $quantity = (int)$quantity;
            if ($quantity <= 0) {
                continue;
            }

            $ticketType = $ticketTypesRepo->getOne(['id' => (int)$ticketTypeId]);
            if (!$ticketType
                || (int)$ticketType->id_venue_event_tickets !== (int)$ticketsConfig->id
                || !(bool)$ticketType->is_active
            ) {
                return TemplateResponse::render(__DIR__ . "/error.twig", [
                    "error" => "One of the selected ticket types is not available for this event"
                ]);
            }

            $available = $ticketInventoryRepo->getAvailableQuantity((int)$ticketTypeId);
            if ($available < $quantity) {
                return TemplateResponse::render(__DIR__ . "/error.twig", [
                    "error" => "Not enough tickets available for '{$ticketType->name}'. Only {$available} tickets left, but you're trying to buy {$quantity}."
                ]);
            }

            $unitPrice = (float)$ticketType->price * (1 - $discountPercentage / 100);
            $totalAmount += $unitPrice * $quantity;
        }

        if ($totalAmount <= 0) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Choose at least one valid ticket"
            ]);
        }

        $stripeService = new StripeServiceV2();

        $customer = $stripeService->getCustomerOnConnectedAccount($customerEmail, $account->stripe_account_id);

        if (!$customer) {
            $customer = $stripeService->createCustomerWithCardOnConnectedAccount(
                $cardToken,
                $customerEmail,
                $customerName,
                $account->stripe_account_id
            );
        } else if ($stripeService->updateCustomerSourceOnConnectedAccount($customer->id, $account->stripe_account_id, $cardToken) === false){
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Failed to create charge"
            ]);
        }

        if (!$customer) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Failed to create charge"
            ]);
        }

        $charge = $stripeService->chargeCustomerOnConnectedAccount(
            $customer->id,
            $totalAmount,
            $account->stripe_account_id
        );

        if (!$charge) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Failed to create charge"
            ]);
        }

        $_SESSION['current_event_id'] = $eventId;
        $_SESSION['selected_tickets'] = $tickets;
        $_SESSION['event_name'] = $event->name ?? 'Event';
        $_SESSION['venue_name'] = $venue->name ?? 'Unknown Venue';
        
        $ticketSalesService = new TicketSalesService();
        $result = $ticketSalesService->confirmTicketPurchase($charge->id, $buyerInfo);

        if ($result['success']) {
            $_SESSION['ticket_codes'] = $result['data']['ticket_codes'] ?? [];
            $_SESSION['ticket_total'] = $totalAmount;
            $_SESSION['event_name'] = $event->name ?? 'Event';
            $_SESSION['venue_name'] = $venue->name ?? 'Unknown Venue';
            $_SESSION['current_stage'] = $result['data']['current_stage'] ?? null;
            
            LocationUtils::redirectInternal("tickets/success");
        } else {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => $result['message']
            ]);
        }

    } catch (Exception $e) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "An error occurred while processing your purchase"
        ]);
    }
});

$router->run();
