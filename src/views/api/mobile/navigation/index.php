<?php

use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();
$router = new Router();

function mobileNavigationForLevel(int $level): array
{
    $common = [
        ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'native_screen' => 'Notifications', 'group' => 'Account'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'route' => 'panel/settings', 'group' => 'Account'],
    ];

    if ($level === 1) {
        return array_merge([
            ['key' => 'home', 'label' => 'Dashboard', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'VNV Events'],
            ['key' => 'orders', 'label' => 'Event Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/management/orders/orders', 'group' => 'VNV Events'],
            ['key' => 'calendar', 'label' => 'Order Calendar', 'icon' => 'calendar-alt', 'route' => 'panel/planner-hub/management/orders/calendar', 'group' => 'VNV Events'],
            ['key' => 'contracts', 'label' => 'Contracts', 'icon' => 'file-contract', 'route' => 'panel/planner-hub/management/orders/contracts', 'group' => 'VNV Events'],
            ['key' => 'clients', 'label' => 'Clients', 'icon' => 'users', 'route' => 'panel/planner-hub/management/crm', 'group' => 'Relationships'],
            ['key' => 'team', 'label' => 'Team', 'icon' => 'user-friends', 'route' => 'panel/planner-hub/management/users', 'group' => 'Relationships'],
            ['key' => 'chat', 'label' => 'Team Chat', 'icon' => 'comments', 'route' => 'panel/planner-hub/team/chat', 'group' => 'Relationships'],
            ['key' => 'requests', 'label' => 'Requests', 'icon' => 'inbox', 'route' => 'panel/home#event-requests', 'group' => 'Operations'],
            ['key' => 'execution', 'label' => 'Event Area', 'icon' => 'camera', 'route' => 'panel/event-execution', 'group' => 'Operations'],
            ['key' => 'storage', 'label' => 'Warehouse & Storage', 'icon' => 'boxes', 'route' => 'panel/planner-hub/management/storage', 'group' => 'Operations'],
            ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'money-check-alt', 'route' => 'panel/planner-hub/management/payroll', 'group' => 'Operations'],
            ['key' => 'store', 'label' => 'Gourmet To Go', 'icon' => 'shopping-bag', 'route' => 'panel/planner-hub/store', 'group' => 'Publishing'],
            ['key' => 'content', 'label' => 'Content Studio', 'icon' => 'pen-nib', 'route' => 'panel/cms', 'group' => 'Publishing'],
            ['key' => 'rewards', 'label' => 'Rewards', 'icon' => 'gift', 'route' => 'panel/loyalty-rewards', 'group' => 'Publishing'],
        ], $common);
    }

    if ($level === 4) {
        return array_merge([
            ['key' => 'home', 'label' => 'Dashboard', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'My Work'],
            ['key' => 'work', 'label' => 'My Work', 'icon' => 'tasks', 'route' => 'panel/planner-hub/team/my-work', 'group' => 'My Work'],
            ['key' => 'orders', 'label' => 'Assigned Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/team/orders/orders', 'group' => 'My Work'],
            ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar-alt', 'route' => 'panel/planner-hub/team/orders/calendar', 'group' => 'My Work'],
            ['key' => 'clock', 'label' => 'Time Clock', 'icon' => 'clock', 'route' => 'panel/planner-hub/team/payroll/clock', 'group' => 'Team'],
            ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'money-check-alt', 'route' => 'panel/planner-hub/team/payroll/pending', 'group' => 'Team'],
            ['key' => 'contract', 'label' => 'My Contract', 'icon' => 'file-contract', 'route' => 'panel/planner-hub/team/contracts', 'group' => 'Team'],
            ['key' => 'chat', 'label' => 'Team Chat', 'icon' => 'comments', 'route' => 'panel/planner-hub/team/chat', 'group' => 'Team'],
            ['key' => 'execution', 'label' => 'Event Area', 'icon' => 'camera', 'route' => 'panel/event-execution', 'group' => 'Events'],
        ], $common);
    }

    return array_merge([
        ['key' => 'home', 'label' => 'Dashboard', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'My Events'],
        ['key' => 'orders', 'label' => 'My Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/orders/orders', 'group' => 'My Events'],
        ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar-alt', 'route' => 'panel/planner-hub/orders/calendar', 'group' => 'My Events'],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'credit-card', 'route' => 'panel/payment-methods', 'group' => 'My Events'],
        ['key' => 'rewards', 'label' => 'Rewards', 'icon' => 'gift', 'route' => 'panel/rewards', 'group' => 'My Events'],
        ['key' => 'event_guest', 'label' => 'Event Guest', 'icon' => 'camera', 'route' => 'panel/event-execution', 'group' => 'Experience'],
        ['key' => 'tickets', 'label' => 'Tickets', 'icon' => 'ticket-alt', 'route' => 'panel/tickets', 'group' => 'Experience'],
        ['key' => 'music', 'label' => 'Music Sessions', 'icon' => 'music', 'route' => 'search/multimedia-sessions/main', 'group' => 'Experience'],
        ['key' => 'store', 'label' => 'Gourmet To Go', 'icon' => 'shopping-bag', 'route' => 'vnv-gourmet-express', 'group' => 'Experience'],
        ['key' => 'store_orders', 'label' => 'Gourmet Orders', 'icon' => 'receipt', 'route' => 'panel/store/orders', 'group' => 'Experience'],
        ['key' => 'messages', 'label' => 'Messages', 'icon' => 'comments', 'route' => 'panel/chat', 'group' => 'Account'],
    ], $common);
}

$router->get(function () {
    $user = ApiAuthService::getAuthenticatedUser();
    if (!$user) {
        return JsonResponse::createResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }

    $level = (int)$user->getLevel();
    return JsonResponse::createResponse([
        'success' => true,
        'data' => [
            'level' => $level,
            'items' => mobileNavigationForLevel($level),
        ],
    ]);
});

$router->run();
