<?php

use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\OrdersCalendarService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $service = new OrdersCalendarService();
    $clientRepo = new UserRepository();

    $weekStart = $service->normalizeWeekStart($_GET['week'] ?? null);
    $weekEnd = $service->weekEnd($weekStart);
    $search = $_GET['search'] ?? null;

    $orders = $service->fetchManagementOrders($search, $weekStart, $weekEnd);
    $clients = $clientRepo->getAllAssociatedClients($user->getOwner());
    $calendar = $service->buildManagementCalendar($orders, $clients, $weekStart);

    return TemplateResponse::render(__DIR__ . '/../../../../../shared/orders-calendar/index.twig', [
        'calendar' => $calendar,
        'search' => $search,
        'listUrl' => '/panel/planner-hub/management/orders/orders',
        'calendarUrl' => '/panel/planner-hub/management/orders/calendar',
    ]);
});

$router->run();
