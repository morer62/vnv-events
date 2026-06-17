<?php

use App\Repositories\StoreCouponsRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrdersRepository;
use App\Repositories\StoreSubscriptionItemsRepository;
use App\Repositories\StoreSubscriptionsRepository;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

/**
 * Weekly renewal amount matches store-subscription-renewals cron logic,
 * including any linked SUBSCRIPTION coupon still valid.
 */
function gourmet_panel_subscription_next_charge_preview(
    object $sub,
    StoreSubscriptionItemsRepository $subItemsRepo,
    StoreCouponsRepository $couponsRepo
): ?array {
    $meals = (int)($sub->meals_count ?? 0);
    if ($meals <= 0) {
        $items = $subItemsRepo->getBySubscription((int)$sub->id);
        foreach ($items ?: [] as $it) {
            $meals += (int)($it->quantity ?? 0);
        }
    }

    $ppm = round((float)($sub->price_per_meal ?? 0), 2);
    $subtotal = round($ppm * max(0, $meals), 2);
    if ($subtotal <= 0) {
        return null;
    }

    $discount = 0.0;
    $subCouponId = (int)($sub->id_coupon ?? 0);
    if ($subCouponId > 0) {
        $coupon = $couponsRepo->getOne(['id' => $subCouponId]);
        if ($coupon) {
            $now = date('Y-m-d H:i:s');
            $valid = strtoupper((string)($coupon->status ?? '')) === StoreCouponsRepository::STATUS_ACTIVE;
            if ($valid && !empty($coupon->starts_at) && (string)$coupon->starts_at > $now) {
                $valid = false;
            }
            if ($valid && !empty($coupon->expires_at) && (string)$coupon->expires_at < $now) {
                $valid = false;
            }
            if ($valid) {
                $dType = strtoupper((string)($coupon->discount_type ?? 'PERCENT'));
                $dVal = (float)($coupon->discount_value ?? 0);
                if ($dType === StoreCouponsRepository::TYPE_PERCENT) {
                    $discount = round(($subtotal * $dVal) / 100, 2);
                } else {
                    $discount = round(min($dVal, $subtotal), 2);
                }
            }
        }
    }

    $total = round(max(0, $subtotal - $discount), 2);
    $nextDate = $sub->next_charge_date ?? null;
    $nextDateStr = $nextDate !== null && $nextDate !== '' ? (string)$nextDate : null;

    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $total,
        'next_charge_date' => $nextDateStr,
    ];
}

$router->get(function () {
    $session = LoginService::getSession();
    $repo = new StoreOrdersRepository();
    $itemsRepo = new StoreOrderItemsRepository();
    $subsRepo = new StoreSubscriptionsRepository();
    $subItemsRepo = new StoreSubscriptionItemsRepository();
    $couponsRepo = new StoreCouponsRepository();
    $ownerId = null;

    $userId = (int)$session->getId();
    $email = method_exists($session, 'getEmail') ? trim((string)$session->getEmail()) : '';

    $byUser = $repo->getAllByUser($userId, 100, $ownerId);
    $byEmail = $email !== '' ? $repo->getAllByGuestEmail($email, 100, $ownerId) : [];
    $seen = [];
    $orders = [];
    foreach (array_merge($byUser ?: [], $byEmail ?: []) as $o) {
        $id = (int)($o->id ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $orders[] = $o;
    }
    usort($orders, function ($a, $b) {
        $ta = strtotime((string)($a->created_at ?? '')) ?: 0;
        $tb = strtotime((string)($b->created_at ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    $activeSub = $subsRepo->getActiveByUser($userId, $ownerId);
    if (!$activeSub && $email !== '') {
        $activeSub = $subsRepo->getActiveByEmail($email, $ownerId);
    }

    $subscriptionNextCharge = null;
    if ($activeSub) {
        $subscriptionNextCharge = gourmet_panel_subscription_next_charge_preview($activeSub, $subItemsRepo, $couponsRepo);
    }

    foreach ($orders as &$order) {
        $shippingParts = array_filter([
            trim((string)($order->shipping_address_1 ?? '')),
            trim((string)($order->shipping_city ?? '')),
            trim((string)(
                trim((string)($order->shipping_state ?? '')) .
                (((string)($order->shipping_zip ?? '') !== '') ? (' ' . trim((string)$order->shipping_zip)) : '')
            ))
        ], static function ($v) {
            return $v !== '';
        });
        $order->shipping_address_display = $shippingParts
            ? implode(', ', $shippingParts)
            : ((string)($order->city ?? '') !== '' ? (string)$order->city : '—');

        $items = $itemsRepo->getByOrder((int)$order->id);
        $modalItems = [];
        foreach ($items ?: [] as $item) {
            $modalItems[] = [
                'name' => $item->product_name_snapshot ?? ('#' . $item->id_product),
                'quantity' => (int)($item->quantity ?? 0),
                'unit_price' => (float)($item->unit_price ?? 0),
                'line_total' => (float)($item->line_total ?? 0),
            ];
        }
        $order->items_modal_json = json_encode($modalItems);
    }
    unset($order);

    foreach ($orders as &$order) {
        $order->status_label = StoreOrdersRepository::statusLabel($order->status ?? '');
        $order->status_badge_class = StoreOrdersRepository::statusBadgeClass($order->status ?? '');
    }
    unset($order);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'orders' => $orders,
        'subscription_next_charge' => $subscriptionNextCharge,
    ]);
});

$router->run();
