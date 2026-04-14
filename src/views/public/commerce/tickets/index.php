<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\TicketSalesService;
use App\Repositories\VenueEventsTicketsRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\TicketSalesStagesRepository;
use App\Repositories\VenueEventsRepository;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $eventId = $_GET['event_id'] ?? null;
    
    if (!$eventId) {
        return TemplateResponse::render(__DIR__ . "/not-found.twig");
    }

    // Solo verificar autenticación si NO hay sesión activa
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        // Redirigir a registro con parámetros de retorno
        $returnUrl = urlencode('/tickets?event_id=' . $eventId);
        LocationUtils::redirectInternal("signup?level=5&return_url=" . $returnUrl);
        return;
    }

    $venueEventsRepo = new VenueEventsRepository();
    $venueEvent = $venueEventsRepo->getOne(['id' => $eventId]);

    if (!$venueEvent) {
        return TemplateResponse::render(__DIR__ . "/not-found.twig");
    }

    $ticketsRepo = new VenueEventsTicketsRepository();
    $ticketTypesRepo = new TicketTypesRepository();
    $salesStagesRepo = new TicketSalesStagesRepository();

    $ticketsConfig = $ticketsRepo->getByVenueEvent($eventId);
    
    if (!$ticketsConfig || !$ticketsConfig->ticket_sales_enabled) {
        return TemplateResponse::render(__DIR__ . "/sales-disabled.twig", [
            "venueEvent" => $venueEvent
        ]);
    }

    $ticketTypes = $ticketTypesRepo->getActiveByEventTickets($ticketsConfig->id);
    $salesStages = $salesStagesRepo->getActiveByEventTickets($ticketsConfig->id);
    $currentStage = $salesStagesRepo->getCurrentStage($ticketsConfig->id);

    if (empty($ticketTypes) || empty($salesStages)) {
        return TemplateResponse::render(__DIR__ . "/not-available.twig", [
            "venueEvent" => $venueEvent
        ]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venueEvent" => $venueEvent,
        "ticketsConfig" => $ticketsConfig,
        "ticketTypes" => $ticketTypes,
        "salesStages" => $salesStages,
        "currentStage" => $currentStage,
        "stripe_key" => $_ENV["STRIPE_PUBLIC"],
        "user" => [
            "id" => $_SESSION['user_id'],
            "name" => $_SESSION['user_name'] ?? '',
            "email" => $_SESSION['user_email'] ?? ''
        ]
    ]);
});

$router->post(function () {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'purchase_tickets') {
        // Verificar autenticación del usuario
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            return JsonResponse::createResponse([
                'success' => false,
                'message' => 'Authentication required',
                'redirect' => '/signup?level=5'
            ]);
        }

        $ticketSalesService = new TicketSalesService();
        
        $purchaseData = [
            'ticket_type_id' => $_POST['ticket_type_id'] ?? 0,
            'sales_stage_id' => $_POST['sales_stage_id'] ?? 0,
            'quantity' => $_POST['quantity'] ?? 1,
            'buyer' => [
                'user_id' => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'] ?? '',
                'email' => $_SESSION['user_email'] ?? '',
                'phone' => $_POST['buyer_phone'] ?? ''
            ],
            'payment_token' => $_POST['payment_token'] ?? ''
        ];

        $result = $ticketSalesService->processTicketPurchaseOld($purchaseData);
        
        return JsonResponse::createResponse($result);
    }
    
    return JsonResponse::createResponse([
        'success' => false,
        'message' => 'Invalid action'
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
