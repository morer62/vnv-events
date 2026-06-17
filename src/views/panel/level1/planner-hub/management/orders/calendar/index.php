<?php

use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\OrdersCalendarService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    if (!$user) {
        return '';
    }

    $week = $_GET['week'] ?? null;
    $status = $_GET['status'] ?? 'all';

    $calendarService = new OrdersCalendarService();
    [$weekStart, $weekEnd] = $calendarService->getWeekBounds($week);

    $ordersRepo = new OrdersRepository();
    $clientRepo = new UserRepository();
    $ownerId = (int)($user->getOwner() ?: $user->getId());

    $orders = $ordersRepo->getFiltered2([
        'id_owner' => $ownerId,
        'is_archived' => 0,
    ], null, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));

    $clients = $clientRepo->getAllAssociatedClients($ownerId);
    $calendar = $calendarService->buildWeek($orders, $clients, $weekStart->format('Y-m-d'), $status);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'calendar' => $calendar,
        'list_route' => 'panel/planner-hub/management/orders/orders',
        'calendar_route' => 'panel/planner-hub/management/orders/calendar',
        'calendar_level_label' => 'Platform operations',
    ]);
});

try {
    $router->run();
} catch (Throwable $e) {
    echo $e->getMessage();
}
