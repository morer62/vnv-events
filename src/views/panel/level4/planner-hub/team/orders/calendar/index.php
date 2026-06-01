<?php

use App\Services\LoginService;
use App\Services\OrdersCalendarService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $service = new OrdersCalendarService();

    $weekStart = $service->normalizeWeekStart($_GET['week'] ?? null);
    $search = trim((string)($_GET['search'] ?? ''));
    $orders = $service->fetchTeamOrders($user->getId());

    if ($search !== '') {
        $orders = array_values(array_filter($orders, function ($order) use ($search) {
            $haystack = strtolower(trim(
                (string)($order->address ?? '') . ' ' .
                (string)($order->institution_name ?? '') . ' ' .
                (string)($order->status_workflow ?? '')
            ));

            return str_contains($haystack, strtolower($search));
        }));
    }

    $calendar = $service->buildTeamCalendar($orders, $weekStart);

    return TemplateResponse::render(__DIR__ . '/../../../../../shared/orders-calendar/index.twig', [
        'calendar' => $calendar,
        'search' => $search,
        'listUrl' => '/panel/planner-hub/team/orders/orders',
        'calendarUrl' => '/panel/planner-hub/team/orders/calendar',
    ]);
});

$router->run();
