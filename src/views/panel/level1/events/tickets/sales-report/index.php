<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Repositories\TicketSalesRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\VenueEventsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $venueEventId = $_GET['event_id'] ?? null;
    
    if (!$user) {
        LocationUtils::redirectInternal("login");
    }
    
    if (!$venueEventId) {
        MessageUtil::setMessage("Event ID is required.");
        LocationUtils::redirectInternal("panel/events/home");
    }

    $venueEventsRepo = new VenueEventsRepository();
    $venueEvent = $venueEventsRepo->getOne(['id' => $venueEventId]);

    if (!$venueEvent) {
        MessageUtil::setMessage("Event not found.");
        LocationUtils::redirectInternal("panel/events/home");
    }

    $ticketSalesRepo = new TicketSalesRepository();
    $ticketTypesRepo = new TicketTypesRepository();

    $filters = [
        'ticket_type_id' => $_GET['ticket_type_id'] ?? null,
        'email' => $_GET['email'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'search' => $_GET['search'] ?? null
    ];

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $salesData = $ticketSalesRepo->getSalesReport($venueEventId, $filters, $limit, $offset);
    $totalSales = $ticketSalesRepo->getSalesReportCount($venueEventId, $filters);
    $totalPages = ceil($totalSales / $limit);

    $ticketTypes = $ticketTypesRepo->getByEventId($venueEventId);
    $stats = $ticketSalesRepo->getSalesReportStats($venueEventId, $filters);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "venueEvent" => $venueEvent,
        "salesData" => $salesData,
        "ticketTypes" => $ticketTypes,
        "filters" => $filters,
        "stats" => $stats,
        "pagination" => [
            "current_page" => $page,
            "total_pages" => $totalPages,
            "total_records" => $totalSales,
            "limit" => $limit
        ]
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
