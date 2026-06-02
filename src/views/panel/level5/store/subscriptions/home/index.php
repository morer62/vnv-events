<?php

use App\Repositories\StoreCouponsRepository;
use App\Repositories\StoreSubscriptionItemsRepository;
use App\Repositories\StoreSubscriptionsRepository;
use App\Repositories\StoreProductsRepository;
use App\Services\LoginService;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $session = LoginService::getSession();
    $repo = new StoreSubscriptionsRepository();
    $subItemsRepo = new StoreSubscriptionItemsRepository();
    $productsRepo = new StoreProductsRepository();
    $couponsRepo = new StoreCouponsRepository();
    $ownerId = AvomealContext::ownerId();

    $userId = (int)$session->getId();
    $email = method_exists($session, 'getEmail') ? trim((string)$session->getEmail()) : '';

    $byUser = $repo->getAllByUser($userId, 100, $ownerId) ?: [];
    $byEmail = $email !== '' ? ($repo->getAllByEmail($email, 100, $ownerId) ?: []) : [];
    $seen = [];
    $subscriptions = [];
    foreach (array_merge($byUser, $byEmail) as $sub) {
        $id = (int)($sub->id ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $subscriptions[] = $sub;
    }
    usort($subscriptions, static function ($a, $b) {
        $ta = strtotime((string)($a->created_at ?? '')) ?: 0;
        $tb = strtotime((string)($b->created_at ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    $publicProducts = $productsRepo->getPublicActiveProducts(500, $ownerId);

    foreach ($subscriptions as &$sub) {
        $meals = (int)($sub->meals_count ?? 0);
        $subItems = $subItemsRepo->getBySubscription((int)$sub->id);
        if ($meals <= 0) {
            foreach ($subItems as $it) {
                $meals += (int)($it->quantity ?? 0);
            }
        }
        $sub->display_meals_count = $meals;
        $ppm = round((float)($sub->price_per_meal ?? 0), 2);
        $subtotalCharge = round($ppm * max(0, $meals), 2);

        $sub->coupon_discount = 0.0;
        $sub->coupon_display = null;
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
                        $sub->coupon_discount = round(($subtotalCharge * $dVal) / 100, 2);
                        $sub->coupon_display = (string)$coupon->code . ' (' . number_format($dVal, 0) . '% off)';
                    } else {
                        $sub->coupon_discount = round(min($dVal, $subtotalCharge), 2);
                        $sub->coupon_display = (string)$coupon->code . ' ($' . number_format($dVal, 2) . ' off)';
                    }
                    if (!empty($coupon->expires_at)) {
                        $sub->coupon_display .= ' until ' . date('M j, Y', strtotime((string)$coupon->expires_at));
                    }
                }
            }
        }

        $sub->weekly_charge_total = round(max(0, $subtotalCharge - $sub->coupon_discount), 2);
        $sub->edit_items_json = json_encode(array_map(static function ($item) {
            return [
                'id_product' => (int)($item->id_product ?? 0),
                'quantity' => max(1, (int)($item->quantity ?? 1)),
                'product_name' => (string)($item->product_name_snapshot ?? '')
            ];
        }, $subItems ?: []));

        $ownerId = (int)($sub->id_owner ?? 0);
        $allowedProducts = array_values(array_filter($publicProducts ?: [], static function ($p) use ($ownerId) {
            $pidOwner = (int)($p->id_owner ?? 0);
            return $pidOwner === 0 || $pidOwner === $ownerId;
        }));
        $sub->edit_products_json = json_encode(array_map(static function ($p) {
            return [
                'id' => (int)($p->id ?? 0),
                'name' => (string)($p->name ?? ''),
                'short_description' => (string)($p->short_description ?? ''),
                'main_image' => (string)($p->main_image ?? ''),
                'price' => (float)($p->promo_price ?? $p->price ?? 0),
            ];
        }, $allowedProducts));
    }
    unset($sub);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "subscriptions" => $subscriptions
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $repo = new StoreSubscriptionsRepository();
    $ownerId = AvomealContext::ownerId();

    $action = trim((string)($_POST['action'] ?? ''));
    $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
    $sessionUserId = (int)$session->getId();
    $sessionEmail = strtolower(trim((string)(method_exists($session, 'getEmail') ? $session->getEmail() : '')));

    if ($subscriptionId <= 0 || !in_array($action, ['pause', 'resume', 'edit_items', 'delete'], true)) {
        MessageUtil::setMessage('Invalid subscription action.');
        LocationUtils::reload();
    }

    $subscription = $repo->getOne(['id' => $subscriptionId, 'id_owner' => $ownerId]);
    if (!$subscription) {
        MessageUtil::setMessage('Subscription not found.');
        LocationUtils::reload();
    }

    $subscriptionUserId = (int)($subscription->id_user ?? 0);
    $subscriptionEmail = strtolower(trim((string)($subscription->email ?? '')));
    $isOwnerByUserId = $subscriptionUserId > 0 && $subscriptionUserId === $sessionUserId;
    $isOwnerByEmail = $subscriptionEmail !== '' && $subscriptionEmail === $sessionEmail;

    if (!$isOwnerByUserId && !$isOwnerByEmail) {
        MessageUtil::setMessage('You cannot modify this subscription.');
        LocationUtils::reload();
    }

    if ((int)($subscription->archive ?? 0) === 1) {
        MessageUtil::setMessage('Subscription is already archived.');
        LocationUtils::reload();
    }

    if ($action === 'edit_items') {
        $subItemsRepo = new StoreSubscriptionItemsRepository();
        $productsRepo = new StoreProductsRepository();
        $productIds = $_POST['item_product_id'] ?? [];
        $quantities = $_POST['item_quantity'] ?? [];
        $minimumMeals = max(5, (int)($subscription->minimum_meals ?? 0));

        if (!is_array($productIds) || !is_array($quantities) || count($productIds) !== count($quantities)) {
            MessageUtil::setMessage('Invalid items data.');
            LocationUtils::reload();
        }

        $ownerId = (int)($subscription->id_owner ?? 0);
        $available = $productsRepo->getPublicActiveProducts(500, $ownerId);
        $allowedById = [];
        foreach ($available as $p) {
            $pid = (int)($p->id ?? 0);
            $pidOwner = (int)($p->id_owner ?? 0);
            if ($pid <= 0) continue;
            if ($pidOwner === 0 || $pidOwner === $ownerId) {
                $allowedById[$pid] = $p;
            }
        }

        $newItems = [];
        $totalMeals = 0;
        foreach ($productIds as $idx => $pidRaw) {
            $pid = (int)$pidRaw;
            $qty = (int)($quantities[$idx] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (!isset($allowedById[$pid])) {
                MessageUtil::setMessage('One of the selected meals is not available.');
                LocationUtils::reload();
            }
            if (isset($newItems[$pid])) {
                $newItems[$pid]['quantity'] += $qty;
            } else {
                $newItems[$pid] = [
                    'id_product' => $pid,
                    'product_name_snapshot' => (string)($allowedById[$pid]->name ?? ('#' . $pid)),
                    'quantity' => $qty
                ];
            }
            $totalMeals += $qty;
        }

        if ($totalMeals < $minimumMeals) {
            MessageUtil::setMessage("Minimum meals for this subscription is {$minimumMeals}.");
            LocationUtils::reload();
        }

        if (count($newItems) === 0) {
            MessageUtil::setMessage('Add at least one meal.');
            LocationUtils::reload();
        }

        $okItems = $subItemsRepo->replaceItems($subscriptionId, array_values($newItems));
        $okSub = $okItems ? $repo->updateMealsCount($subscriptionId, $totalMeals) : false;

        MessageUtil::setMessage($okSub ? 'Subscription updated successfully.' : 'Could not update subscription.');
        LocationUtils::reload();
    }

    $status = strtoupper((string)($subscription->status ?? ''));

    if ($action === 'pause') {
        if ($status !== StoreSubscriptionsRepository::STATUS_ACTIVE) {
            MessageUtil::setMessage('Only active subscriptions can be paused.');
            LocationUtils::reload();
        }

        $ok = $repo->pause($subscriptionId);
        MessageUtil::setMessage($ok ? 'Subscription paused successfully.' : 'Could not pause subscription.');
        LocationUtils::reload();
    }

    if ($action === 'resume') {
        if ($status !== StoreSubscriptionsRepository::STATUS_PAUSED) {
            MessageUtil::setMessage('Only paused subscriptions can be resumed.');
            LocationUtils::reload();
        }

        $ok = $repo->activate($subscriptionId);
        MessageUtil::setMessage($ok ? 'Subscription resumed successfully.' : 'Could not resume subscription.');
        LocationUtils::reload();
    }

    if ($action === 'delete') {
        $ok = $repo->archive($subscriptionId);
        MessageUtil::setMessage($ok ? 'Subscription archived successfully.' : 'Could not archive subscription.');
        LocationUtils::reload();
    }

    MessageUtil::setMessage('Invalid subscription action.');
    LocationUtils::reload();
});

$router->run();
