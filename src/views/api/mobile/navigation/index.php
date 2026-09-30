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
            ['key' => 'home', 'label' => 'VNV Dashboard', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'VNV Events'],
            ['key' => 'orders', 'label' => 'Event Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/management/orders/orders/execution', 'group' => 'VNV Events'],
            ['key' => 'calendar', 'label' => 'Order Calendar', 'icon' => 'calendar-alt', 'route' => 'panel/planner-hub/management/orders/calendar', 'group' => 'VNV Events'],
            ['key' => 'contracts', 'label' => 'Contracts', 'icon' => 'file-contract', 'route' => 'panel/planner-hub/management/orders/contracts', 'group' => 'VNV Events'],
            ['key' => 'clients', 'label' => 'Clients', 'icon' => 'user-check', 'route' => 'panel/planner-hub/management/users?active_tab=clients', 'group' => 'VNV Events'],
            ['key' => 'team', 'label' => 'Team', 'icon' => 'users', 'route' => 'panel/planner-hub/management/users', 'group' => 'VNV Events'],
            ['key' => 'crm', 'label' => 'CRM', 'icon' => 'address-book', 'route' => 'panel/planner-hub/management/crm', 'group' => 'VNV Events'],
            ['key' => 'chat', 'label' => 'Team Chat', 'icon' => 'comments', 'route' => 'panel/planner-hub/team/chat', 'group' => 'VNV Events'],
            ['key' => 'content', 'label' => 'Content Studio', 'icon' => 'pen-nib', 'route' => 'panel/cms/pages/create?mode=ai', 'group' => 'Publishing'],
            ['key' => 'manager_scheduling', 'label' => 'Manager Scheduling', 'icon' => 'users-cog', 'route' => 'panel/manager-scheduling', 'group' => 'Publishing'],
            ['key' => 'cms', 'label' => 'CMS Library', 'icon' => 'database', 'route' => 'panel/cms', 'group' => 'Publishing'],
            ['key' => 'agents', 'label' => 'Agents & Automations', 'icon' => 'cpu', 'route' => 'panel/agents', 'group' => 'AI Operations'],
            ['key' => 'approvals', 'label' => 'Approval Center', 'icon' => 'check-square', 'route' => 'panel/agents/approvals', 'group' => 'AI Operations'],
            ['key' => 'social', 'label' => 'Social Media Agent', 'icon' => 'share-alt', 'route' => 'panel/social-media-agent', 'group' => 'AI Operations'],
            ['key' => 'conversations', 'label' => 'Meta Conversations', 'icon' => 'comments', 'route' => 'panel/agents/conversations', 'group' => 'AI Operations'],
            ['key' => 'multimedia', 'label' => 'Multimedia Sessions', 'icon' => 'film', 'route' => 'panel/multimedia-sessions', 'group' => 'AI Operations'],
            ['key' => 'forums', 'label' => 'Forums', 'icon' => 'comments', 'route' => 'panel/forum', 'group' => 'AI Operations'],
            ['key' => 'invitations', 'label' => 'Tickets / RSVP', 'icon' => 'paper-plane', 'route' => 'panel/event-invitations', 'group' => 'AI Operations'],
            ['key' => 'store_orders', 'label' => 'Store Orders', 'icon' => 'shopping-bag', 'route' => 'panel/planner-hub/store/orders/home', 'group' => 'Store'],
            ['key' => 'products', 'label' => 'Products', 'icon' => 'boxes', 'route' => 'panel/planner-hub/store/products/home', 'group' => 'Store'],
            ['key' => 'categories', 'label' => 'Product Categories', 'icon' => 'tag', 'route' => 'panel/planner-hub/store/categories/home', 'group' => 'Store'],
            ['key' => 'attributes', 'label' => 'Attributes', 'icon' => 'sliders-h', 'route' => 'panel/planner-hub/store/attributes/home', 'group' => 'Store'],
            ['key' => 'carts', 'label' => 'Carts', 'icon' => 'shopping-cart', 'route' => 'panel/planner-hub/store/carts/home', 'group' => 'Store'],
            ['key' => 'subscriptions', 'label' => 'Subscriptions', 'icon' => 'redo', 'route' => 'panel/planner-hub/store/subscriptions/home', 'group' => 'Store'],
            ['key' => 'coupons', 'label' => 'Coupons', 'icon' => 'percent', 'route' => 'panel/planner-hub/store/coupons/home', 'group' => 'Store'],
            ['key' => 'store_payments', 'label' => 'Store Payments', 'icon' => 'credit-card', 'route' => 'panel/planner-hub/store/payments/home', 'group' => 'Store'],
            ['key' => 'payment_providers', 'label' => 'Payment Providers', 'icon' => 'credit-card', 'route' => 'panel/planner-hub/settings/payment-providers', 'group' => 'Settings'],
            ['key' => 'smtp', 'label' => 'SMTP Providers', 'icon' => 'paper-plane', 'route' => 'panel/planner-hub/settings/smtp', 'group' => 'Settings'],
        ], $common);
    }

    if ($level === 4) {
        return array_merge([
            ['key' => 'home', 'label' => 'Team Home', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'My Dashboard'],
            ['key' => 'work', 'label' => 'My Work', 'icon' => 'tasks', 'route' => 'panel/planner-hub/team/my-work', 'group' => 'My Work'],
            ['key' => 'orders', 'label' => 'Assigned Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/team/orders/orders', 'group' => 'My Work'],
            ['key' => 'store_workspace', 'label' => 'Store Workspace', 'icon' => 'shopping-bag', 'route' => 'panel/planner-hub/team/store/home', 'group' => 'My Work'],
            ['key' => 'clock', 'label' => 'Time Clock', 'icon' => 'clock', 'route' => 'panel/planner-hub/team/payroll/clock', 'group' => 'Team'],
            ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'money-check-alt', 'route' => 'panel/planner-hub/team/payroll/pending', 'group' => 'Team'],
            ['key' => 'contract', 'label' => 'My Contract', 'icon' => 'file-contract', 'route' => 'panel/planner-hub/team/contracts', 'group' => 'Team'],
            ['key' => 'availability', 'label' => 'My Availability', 'icon' => 'calendar-alt', 'route' => 'panel/manager-availability', 'group' => 'Team'],
            ['key' => 'chat', 'label' => 'Team Chat', 'icon' => 'comments', 'route' => 'panel/planner-hub/team/chat', 'group' => 'Team'],
        ], $common);
    }

    return array_merge([
        ['key' => 'home', 'label' => 'Client Home', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'VNV Events'],
        ['key' => 'orders', 'label' => 'My Event Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/orders/orders', 'group' => 'VNV Events'],
        ['key' => 'calendar', 'label' => 'Upcoming Events', 'icon' => 'calendar-alt', 'route' => 'panel/planner-hub/orders/calendar', 'group' => 'VNV Events'],
        ['key' => 'event_guest', 'label' => 'My Event Area', 'icon' => 'camera', 'route' => 'panel/event-execution', 'group' => 'VNV Events'],
        ['key' => 'tickets', 'label' => 'My Tickets', 'icon' => 'ticket-alt', 'route' => 'panel/tickets', 'group' => 'VNV Events'],
        ['key' => 'contracts', 'label' => 'Contracts & Files', 'icon' => 'file-text', 'route' => 'panel/planner-hub/orders/orders', 'group' => 'Payments'],
        ['key' => 'payments', 'label' => 'Payment Methods', 'icon' => 'credit-card', 'route' => 'panel/payment-methods', 'group' => 'Payments'],
        ['key' => 'billing', 'label' => 'Billing Details', 'icon' => 'address-book', 'route' => 'panel/billing', 'group' => 'Payments'],
        ['key' => 'rewards', 'label' => 'My Rewards', 'icon' => 'gift', 'route' => 'panel/rewards', 'group' => 'Payments'],
        ['key' => 'store_orders', 'label' => 'My Store Orders', 'icon' => 'shopping-bag', 'route' => 'panel/store/orders/home', 'group' => 'VNV To Go'],
        ['key' => 'store_subscriptions', 'label' => 'Recurring Orders', 'icon' => 'redo', 'route' => 'panel/store/subscriptions/home', 'group' => 'VNV To Go'],
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
