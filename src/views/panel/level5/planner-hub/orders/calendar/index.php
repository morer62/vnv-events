<?php

use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Services\OrdersCalendarService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $service = new OrdersCalendarService();
    $profileRepo = new InstitutionProfileRepository();

    $weekStart = $service->normalizeWeekStart($_GET['week'] ?? null);
    $search = trim((string)($_GET['search'] ?? ''));
    $orders = $service->fetchClientOrders((int)$user->getId());

    foreach ($orders as $order) {
        $order->institution = $profileRepo->getByOwner((int)($order->id_owner ?? 0));
    }

    if ($search !== '') {
        $needle = strtolower($search);
        $orders = array_values(array_filter($orders, function ($order) use ($needle) {
            $haystack = strtolower(trim(
                (string)($order->address ?? '') . ' ' .
                (string)($order->institution->company_name ?? '') . ' ' .
                (string)($order->status_workflow ?? '')
            ));

            return str_contains($haystack, $needle);
        }));
    }

    $calendar = $service->buildClientCalendar($orders, $weekStart);

    return TemplateResponse::render(__DIR__ . '/../../../../shared/orders-calendar/index.twig', [
        'calendar' => $calendar,
        'search' => $search,
        'listUrl' => '/panel/planner-hub/orders/orders',
        'calendarUrl' => '/panel/planner-hub/orders/calendar',
    ]);
});

$router->run();
