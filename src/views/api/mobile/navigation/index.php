<?php

use App\Services\ApiAuthService;
use App\Services\AutomationCenterService;
use App\Repositories\StoreUserRolesRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();
$router = new Router();

function mobileNavigationForUser($user): array
{
    $level = (int)$user->getLevel();
    $common = [
        ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'native_screen' => 'Notifications', 'group' => 'Account'],
    ];

    if ($level === 1) {
        return array_merge([
            ['key' => 'home', 'label' => 'VNV Dashboard', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'VNV Events'],
            ['key' => 'orders', 'label' => 'Event Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/management/orders/orders/execution', 'group' => 'VNV Events'],
            ['key' => 'calendar', 'label' => 'Order Calendar', 'icon' => 'calendar-alt', 'route' => 'panel/planner-hub/management/orders/calendar', 'group' => 'VNV Events'],
            ['key' => 'event_area', 'label' => 'Event Area', 'icon' => 'camera', 'route' => 'panel/event-execution', 'group' => 'VNV Events'],
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
            ['key' => 'delivery_pricing', 'label' => 'Delivery Pricing', 'icon' => 'truck', 'route' => 'panel/planner-hub/store/delivery-pricing', 'group' => 'Store'],
            ['key' => 'loyalty', 'label' => 'Rewards & Points', 'icon' => 'gift', 'route' => 'panel/planner-hub/management/orders/loyalty', 'group' => 'VNV Events'],
            ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'route' => 'panel/settings', 'group' => 'Settings'],
            ['key' => 'automation', 'label' => 'Automation Center', 'icon' => 'clock', 'route' => 'panel/planner-hub/settings/automation', 'group' => 'Settings'],
            ['key' => 'payment_providers', 'label' => 'Payment Providers', 'icon' => 'credit-card', 'route' => 'panel/planner-hub/settings/payment-providers', 'group' => 'Settings'],
            ['key' => 'smtp', 'label' => 'SMTP Providers', 'icon' => 'paper-plane', 'route' => 'panel/planner-hub/settings/smtp', 'group' => 'Settings'],
        ], $common);
    }

    if ($level === 4) {
        $storeRole = 'general';
        try {
            $storeRole = (new StoreUserRolesRepository())->getRoleValueByOwnerAndUser(
                (int)($user->getOwner() ?: 0),
                (int)$user->getId()
            ) ?: 'general';
        } catch (\Throwable $exception) {
            // The general workspace remains a valid fallback when the optional role table is unavailable.
        }
        $storeRoute = $storeRole === 'kitchen'
            ? 'panel/planner-hub/team/store/kitchen/home'
            : ($storeRole === 'delivery' ? 'panel/planner-hub/team/store/delivery/home' : 'panel/planner-hub/team/store/home');
        $storeLabel = $storeRole === 'kitchen'
            ? 'Kitchen Workspace'
            : ($storeRole === 'delivery' ? 'Delivery Workspace' : 'Store Workspace');

        $items = [
            ['key' => 'home', 'label' => 'Team Home', 'icon' => 'home', 'native_screen' => 'Panel', 'group' => 'My Dashboard'],
            ['key' => 'orders', 'label' => 'Assigned Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/team/orders/orders', 'group' => 'My Work'],
            ['key' => 'work', 'label' => 'My Work', 'icon' => 'tasks', 'route' => 'panel/planner-hub/team/my-work', 'group' => 'My Work'],
            ['key' => 'store_workspace', 'label' => $storeLabel, 'icon' => 'shopping-bag', 'route' => $storeRoute, 'group' => 'My Work'],
            ['key' => 'chat', 'label' => 'Team Chat', 'icon' => 'comments', 'route' => 'panel/planner-hub/team/chat', 'group' => 'My Work'],
            ['key' => 'contract', 'label' => 'My Contract', 'icon' => 'file-contract', 'route' => 'panel/planner-hub/team/contracts', 'group' => 'My Work'],
            ['key' => 'availability', 'label' => 'My Availability', 'icon' => 'calendar-alt', 'route' => 'panel/manager-availability', 'group' => 'My Work'],
            ['key' => 'clock', 'label' => 'Clock In / Out', 'icon' => 'clock', 'route' => 'panel/planner-hub/team/payroll/clock', 'group' => 'Time'],
            ['key' => 'event_area', 'label' => 'Event Area', 'icon' => 'camera', 'route' => 'panel/event-execution', 'group' => 'My Work'],
        ];
        try { if ((new AutomationCenterService((int)$user->getOwner()))->isReviewer((int)$user->getId())) $items[]=['key'=>'mochi','label'=>'Mochi Review','icon'=>'comments','route'=>'panel/planner-hub/settings/automation','group'=>'My Work']; } catch (\Throwable) {}
        $approvedTools = [
            'orders' => ['key' => 'approved_orders', 'label' => 'Orders', 'icon' => 'briefcase', 'route' => 'panel/planner-hub/management/orders', 'group' => 'Approved Tools'],
            'crm' => ['key' => 'approved_crm', 'label' => 'CRM', 'icon' => 'users', 'route' => 'panel/planner-hub/management/crm', 'group' => 'Approved Tools'],
            'storage' => ['key' => 'approved_storage', 'label' => 'Inventory / Storage', 'icon' => 'boxes', 'route' => 'panel/planner-hub/management/storage', 'group' => 'Approved Tools'],
            'users' => ['key' => 'approved_team', 'label' => 'Team', 'icon' => 'user-check', 'route' => 'panel/planner-hub/management/users', 'group' => 'Approved Tools'],
        ];
        foreach ($approvedTools as $module => $item) {
            if ($user->hasPermissionForModule($module)) {
                $items[] = $item;
            }
        }
        return array_merge($items, [
            ['key' => 'settings', 'label' => 'Profile', 'icon' => 'cog', 'route' => 'panel/settings', 'group' => 'Account'],
            ['key' => 'client_view', 'label' => 'Client View', 'icon' => 'user-check', 'action' => 'client_view', 'group' => 'Account'],
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
        ['key' => 'messages', 'label' => 'Messages', 'icon' => 'comments', 'route' => 'panel/chat', 'group' => 'Support'],
        ['key' => 'new_request', 'label' => 'New Event Request', 'icon' => 'paper-plane', 'action' => 'open_request', 'group' => 'Support'],
        ['key' => 'settings', 'label' => 'Account Settings', 'icon' => 'cog', 'route' => 'panel/settings', 'group' => 'Support'],
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
            'navigation_version' => 3,
            'items' => mobileNavigationForUser($user),
        ],
    ]);
});

$router->run();
