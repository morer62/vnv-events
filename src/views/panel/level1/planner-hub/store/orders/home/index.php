<?php

use App\Repositories\StoreOrdersRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrderWorkflowRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\AvomealContext;

$router = new Router();

$router->get(function () {
    $ordersRepo = new StoreOrdersRepository();
    $itemsRepo = new StoreOrderItemsRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $storeRolesRepo = new StoreUserRolesRepository();

    $ownerId = AvomealContext::ownerId();

    $weekStartInput = trim($_GET['week_start'] ?? '');
    $weekEndInput = trim($_GET['week_end'] ?? '');
    $paymentStatus = trim($_GET['payment_status'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $email = trim($_GET['email'] ?? '');

    $today = new DateTimeImmutable('now');
    $weekStart = $weekStartInput !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d', $weekStartInput)
        : $today->modify('monday this week');
    $weekEnd = $weekEndInput !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d', $weekEndInput)
        : $today->modify('sunday this week');

    if (!$weekStart) {
        $weekStart = $today->modify('monday this week');
    }
    if (!$weekEnd) {
        $weekEnd = $today->modify('sunday this week');
    }

    $orders = $ordersRepo->getByOwnerAndDateRange(
        $ownerId,
        $weekStart->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        $weekEnd->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
        300
    );

    if ($paymentStatus !== '') {
        $orders = array_values(array_filter($orders, function ($o) use ($paymentStatus) {
            return strtoupper((string)$o->payment_status) === strtoupper($paymentStatus);
        }));
    }
    if ($status !== '') {
        $orders = array_values(array_filter($orders, function ($o) use ($status) {
            return strtoupper((string)$o->status) === strtoupper($status);
        }));
    }
    if ($email !== '') {
        $orders = array_values(array_filter($orders, function ($o) use ($email) {
            return stripos((string)($o->guest_email ?? ''), $email) !== false;
        }));
    }

    $deliveryUsers = $storeRolesRepo->getUsersByOwnerAndRole($ownerId, 'delivery');
    $kitchenUsers = $storeRolesRepo->getUsersByOwnerAndRole($ownerId, 'kitchen');
    $orderIds = array_map(fn($o) => (int)$o->id, $orders);
    $workflowMap = $workflowRepo->getMapByOrders($orderIds);
    $deliveryUsersById = [];
    $kitchenUsersById = [];
    foreach ($deliveryUsers as $u) {
        $deliveryUsersById[(int)$u->id_user] = trim(($u->name ?? '') . ' ' . ($u->lastname ?? ''));
    }
    foreach ($kitchenUsers as $u) {
        $kitchenUsersById[(int)$u->id_user] = trim(($u->name ?? '') . ' ' . ($u->lastname ?? ''));
    }

    foreach ($orders as &$order) {
        $shippingParts = array_filter([
            trim((string)($order->shipping_address_1 ?? '')),
            trim((string)($order->shipping_city ?? '')),
            trim((string)(
                trim((string)($order->shipping_state ?? '')) .
                (((string)($order->shipping_zip ?? '') !== '') ? (' ' . trim((string)$order->shipping_zip)) : '')
            ))
        ], function ($v) {
            return $v !== '';
        });
        $order->shipping_address_display = $shippingParts
            ? implode(', ', $shippingParts)
            : ((string)($order->city ?? '') !== '' ? (string)$order->city : '—');

        $items = $itemsRepo->getByOrder((int)$order->id);
        $order->items_summary = [];
        $order->items_meals_total = 0;
        $modalItems = [];

        foreach ($items as $idx => $item) {
            $order->items_meals_total += (int)($item->quantity ?? 0);
            if ($idx < 3) {
                $order->items_summary[] = sprintf(
                    '%s × %d',
                    $item->product_name_snapshot ?? ('#' . $item->id_product),
                    (int)($item->quantity ?? 0)
                );
            }

            $modalItems[] = [
                'name' => $item->product_name_snapshot ?? ('#' . $item->id_product),
                'quantity' => (int)($item->quantity ?? 0),
                'unit_price' => (float)($item->unit_price ?? 0),
                'line_total' => (float)($item->line_total ?? 0),
            ];
        }
        if (count($items) > 3) {
            $order->items_summary[] = '+' . (count($items) - 3) . ' more';
        }

        $order->items_modal = $modalItems;
        $order->items_modal_json = json_encode($modalItems);
        $wf = $workflowMap[(int)$order->id] ?? null;
        $order->status_label = StoreOrdersRepository::statusLabel($order->status ?? '');
        $order->status_badge_class = StoreOrdersRepository::statusBadgeClass($order->status ?? '');
        $order->kitchen_user_id = $wf ? (int)($wf->kitchen_user_id ?? 0) : 0;
        $order->delivery_user_id = $wf ? (int)($wf->delivery_user_id ?? 0) : 0;
        $order->kitchen_assignee_name = $order->kitchen_user_id > 0
            ? ($kitchenUsersById[$order->kitchen_user_id] ?? '')
            : '';
        $order->delivery_assignee_name = $order->delivery_user_id > 0
            ? ($deliveryUsersById[$order->delivery_user_id] ?? '')
            : '';
    }
    unset($order);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "deliveryUsers" => $deliveryUsers,
        "kitchenUsers" => $kitchenUsers,
        "orderStatusOptions" => StoreOrdersRepository::statusOptions(),
        "filters" => [
            "week_start" => $weekStart->format('Y-m-d'),
            "week_end" => $weekEnd->format('Y-m-d'),
            "payment_status" => $paymentStatus,
            "status" => $status,
            "email" => $email
        ]
    ]);
});

$router->post(function () {
    $ordersRepo = new StoreOrdersRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $ownerId = AvomealContext::ownerId();

    $action = $_POST['action'] ?? '';
    $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

    if ($orderId <= 0) {
        MessageUtil::setMessage('Invalid order id.');
        LocationUtils::reload();
    }

    $order = $ordersRepo->getById($orderId);
    if (!$order || (int)($order->id_owner ?? 0) !== $ownerId) {
        MessageUtil::setMessage('Order not found for Avomeal.');
        LocationUtils::reload();
    }

    if ($action === 'update_status') {
        $newStatus = trim($_POST['status'] ?? '');
        if ($newStatus === '' || !array_key_exists($newStatus, StoreOrdersRepository::statusOptions())) {
            MessageUtil::setMessage('Select a valid status.');
            LocationUtils::reload();
        }

        $ok = $ordersRepo->updateStatus($orderId, $newStatus);
        if ($ok && in_array($newStatus, [
            StoreOrdersRepository::STATUS_READY,
            StoreOrdersRepository::STATUS_READY_FOR_DELIVERY,
        ], true)) {
            $workflowRepo->markKitchenReady($orderId);
        }
        if ($ok && $newStatus === StoreOrdersRepository::STATUS_OUT_FOR_DELIVERY) {
            $workflowRepo->markSending($orderId);
        }
        if ($ok && in_array($newStatus, [
            StoreOrdersRepository::STATUS_DELIVERED,
            StoreOrdersRepository::STATUS_COMPLETED,
        ], true)) {
            $workflowRepo->markDelivered($orderId, null, null);
        }
        MessageUtil::setMessage($ok ? 'Order status updated.' : 'Failed to update order status.');
        LocationUtils::reload();
    }

    if ($action === 'update_payment_status') {
        $newPayment = trim($_POST['payment_status'] ?? '');
        $ok = false;
        if ($newPayment === StoreOrdersRepository::PAYMENT_PAID) {
            $ok = $ordersRepo->markAsPaid($orderId);
        } elseif ($newPayment === StoreOrdersRepository::PAYMENT_FAILED) {
            $ok = $ordersRepo->markAsFailed($orderId);
        } elseif ($newPayment === StoreOrdersRepository::PAYMENT_REFUNDED) {
            $ok = $ordersRepo->markAsRefunded($orderId);
        }
        MessageUtil::setMessage($ok ? 'Payment status updated.' : 'Failed to update payment status.');
        LocationUtils::reload();
    }

    if ($action === 'assign_delivery') {
        $kitchenUserId = isset($_POST['kitchen_user_id']) && $_POST['kitchen_user_id'] !== ''
            ? (int)$_POST['kitchen_user_id']
            : null;
        $deliveryUserId = isset($_POST['delivery_user_id']) && $_POST['delivery_user_id'] !== ''
            ? (int)$_POST['delivery_user_id']
            : null;
        $ok = $workflowRepo->upsertAssignments($ownerId, $orderId, $kitchenUserId, $deliveryUserId);
        MessageUtil::setMessage($ok ? 'Order assignments updated.' : 'Failed to update order assignments.');
        LocationUtils::reload();
    }

    MessageUtil::setMessage('Invalid action.');
    LocationUtils::reload();
});

$router->run();
