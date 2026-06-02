<?php

/**
 * Store Subscription Renewals Cron Job (Avomeal)
 *
 * - Charges ACTIVE subscriptions only when next_charge_date is due
 * - Creates a store order + order items + payment record
 * - Sends customer email on success
 * - Creates admin notification on failure
 *
 * Suggested schedule: daily (e.g., 2:10 AM)
 * Command: php C:\xampp\htdocs\Avomeal\src\cron\store-subscription-renewals.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use App\Repositories\NotificationsRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\StoreCouponsRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrdersRepository;
use App\Repositories\StorePaymentsRepository;
use App\Repositories\StoreSubscriptionItemsRepository;
use App\Repositories\StoreSubscriptionsRepository;
use App\Repositories\UserCardsRepository;
use App\Services\EmailServiceFactory;
use App\Services\StripeService;
use App\Utils\LocationUtils;

$logFile = __DIR__ . '/../../.logs/store_subscriptions_' . date('Y-m-d') . '.log';

function logLine(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function acquireLockOrExit(): void
{
    $lockPath = __DIR__ . '/../../.logs/store_subscription_renewals.lock';
    @mkdir(dirname($lockPath), 0777, true);
    $fp = fopen($lockPath, 'c+');
    if (!$fp) {
        logLine('ERROR: Could not open lock file.');
        exit(1);
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        logLine('Another instance is already running. Exiting.');
        exit(0);
    }
}

function adminNotify(int $adminUserId, string $message, string $linkPath): void
{
    if ($adminUserId <= 0) {
        return;
    }
    $notificationsRepo = new NotificationsRepository();
    $notificationsRepo->add([
        'id_user' => $adminUserId,
        'mensaje' => $message,
        'link' => $linkPath,
        'leido' => 0
    ]);
}

function chargeSquarePaymentCurl(
    object $provider,
    string $sourceId,
    int $amountCents,
    string $email,
    string $note = '',
    ?string $customerIdForCardOnFile = null
): array {
    $accessToken = trim((string)($provider->api_key ?? ''));
    $locationId = trim((string)($provider->location_id ?? ''));
    $currency = strtoupper((string)($provider->currency ?? 'USD'));

    if ($accessToken === '' || $locationId === '') {
        return [
            'success' => false,
            'message' => 'Square is not configured.'
        ];
    }

    $env = strtolower((string)($provider->environment ?? 'sandbox'));
    $baseUrl = $env === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $url = $baseUrl . '/v2/payments';

    $payload = [
        'source_id' => $sourceId,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'location_id' => $locationId,
        'amount_money' => [
            'amount' => $amountCents,
            'currency' => $currency
        ],
        'autocomplete' => true,
        'buyer_email_address' => $email,
        'note' => $note
    ];

    if ($customerIdForCardOnFile) {
        $payload['customer_id'] = $customerIdForCardOnFile;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Square-Version: 2024-12-18',
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 45
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'message' => 'Payment gateway connection error.'
        ];
    }

    $data = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['payment']['id'])) {
        return [
            'success' => true,
            'payment_id' => $data['payment']['id'],
            'reference' => $data['payment']['receipt_number'] ?? null,
            'raw' => $response
        ];
    }

    $errorMessage = 'Payment could not be processed.';
    if (!empty($data['errors'][0]['detail'])) {
        $errorMessage = (string)$data['errors'][0]['detail'];
    }

    return [
        'success' => false,
        'message' => $errorMessage,
        'raw' => $response
    ];
}

function getSquareCardCustomerIdCurl(object $provider, string $cardId): ?string
{
    $accessToken = trim((string)($provider->api_key ?? ''));
    if ($accessToken === '' || trim($cardId) === '') {
        return null;
    }

    $env = strtolower((string)($provider->environment ?? 'sandbox'));
    $baseUrl = $env === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $url = $baseUrl . '/v2/cards/' . rawurlencode($cardId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Square-Version: 2024-12-18',
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 45
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!($httpCode >= 200 && $httpCode < 300)) {
        return null;
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || empty($data['card']['customer_id'])) {
        return null;
    }

    return (string)$data['card']['customer_id'];
}

function sendSubscriptionRenewalEmail(
    int $ownerId,
    string $recipientEmail,
    string $recipientName,
    int $orderId,
    string $publicToken,
    float $amount,
    string $nextChargeDate
): void {
    $baseUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
    $accessPath = (string)LocationUtils::pathFor("store/order-access?token=" . urlencode($publicToken));
    $accessUrl = preg_match('#^https?://#i', $accessPath) ? $accessPath : ($baseUrl . '/' . ltrim($accessPath, '/'));

    $subject = "Subscription renewed - Order #{$orderId}";
    $message = '
        <div style="font-family:Arial,sans-serif;color:#222;line-height:1.6;max-width:700px;margin:0 auto;">
            <h2 style="margin-bottom:6px;">Subscription renewal successful</h2>
            <p style="margin-top:0;">Hi ' . htmlspecialchars($recipientName ?: 'Customer') . ', your weekly subscription was renewed successfully.</p>
            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin:16px 0;">
                <p style="margin:0;"><strong>Order:</strong> #' . (int)$orderId . '</p>
                <p style="margin:0;"><strong>Amount charged:</strong> $ ' . number_format($amount, 2) . '</p>
                <p style="margin:0;"><strong>Next charge date:</strong> ' . htmlspecialchars($nextChargeDate) . '</p>
            </div>
            <p style="margin-top:18px;">
                <a href="' . htmlspecialchars($accessUrl) . '" style="display:inline-block;padding:10px 16px;background:#111827;color:#fff;text-decoration:none;border-radius:6px;">
                    View order
                </a>
            </p>
        </div>
    ';

    EmailServiceFactory::sendWithOwnerProvider($ownerId, $recipientEmail, $subject, $message, true);
}

acquireLockOrExit();
logLine("=== Store Subscription Renewals started ===");

$today = date('Y-m-d');

$subsRepo = new StoreSubscriptionsRepository();
$subItemsRepo = new StoreSubscriptionItemsRepository();
$ordersRepo = new StoreOrdersRepository();
$orderItemsRepo = new StoreOrderItemsRepository();
$paymentsRepo = new StorePaymentsRepository();
$providersRepo = new PaymentProvidersRepository();
$cardsRepo = new UserCardsRepository();
$couponsRepo = new StoreCouponsRepository();
$stripe = new StripeService();

$due = $subsRepo->getDueForCharge($today);
logLine('Due subscriptions: ' . count($due));

$processed = 0;
$successful = 0;
$failed = 0;
$skipped = 0;

foreach ($due as $sub) {
    $processed++;

    $subId = (int)($sub->id ?? 0);
    $ownerId = (int)($sub->id_owner ?? 0);
    $userId = (int)($sub->id_user ?? 0);
    $email = trim((string)($sub->email ?? ''));
    $name = trim((string)($sub->full_name ?? ''));

    if ($subId <= 0 || $ownerId <= 0) {
        $skipped++;
        continue;
    }

    $status = strtoupper((string)($sub->status ?? ''));
    if ($status !== StoreSubscriptionsRepository::STATUS_ACTIVE) {
        $skipped++;
        continue;
    }

    if (!empty($sub->last_charge_date) && (string)$sub->last_charge_date === $today) {
        $skipped++;
        continue;
    }

    $items = $subItemsRepo->getBySubscription($subId);
    if (!$items) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: subscription #{$subId} has no items.",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $mealsCount = (int)($sub->meals_count ?? 0);
    if ($mealsCount <= 0) {
        $mealsCount = 0;
        foreach ($items as $it) {
            $mealsCount += (int)($it->quantity ?? 0);
        }
    }

    $pricePerMeal = round((float)($sub->price_per_meal ?? 0), 2);
    $subtotalAmount = round($pricePerMeal * max(0, $mealsCount), 2);
    if ($subtotalAmount <= 0) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: subscription #{$subId} has invalid amount.",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $couponDiscount = 0.0;
    $couponCodeUsed = null;
    $couponIdUsed = null;
    $subCouponId = (int)($sub->id_coupon ?? 0);
    if ($subCouponId > 0) {
        $coupon = $couponsRepo->getOne(['id' => $subCouponId]);
        if ($coupon) {
            $couponStillValid = true;
            if (strtoupper((string)($coupon->status ?? '')) !== StoreCouponsRepository::STATUS_ACTIVE) {
                $couponStillValid = false;
            }
            $now = date('Y-m-d H:i:s');
            if ($couponStillValid && !empty($coupon->starts_at) && (string)$coupon->starts_at > $now) {
                $couponStillValid = false;
            }
            if ($couponStillValid && !empty($coupon->expires_at) && (string)$coupon->expires_at < $now) {
                $couponStillValid = false;
                logLine("Coupon #{$subCouponId} expired for subscription #{$subId}, removing link.");
                $subsRepo->update([
                    'coupon_code' => null,
                    'id_coupon' => null,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $subId]);
            }
            if ($couponStillValid) {
                $discountType = strtoupper((string)($coupon->discount_type ?? 'PERCENT'));
                $discountValue = (float)($coupon->discount_value ?? 0);
                if ($discountType === StoreCouponsRepository::TYPE_PERCENT) {
                    $couponDiscount = round(($subtotalAmount * $discountValue) / 100, 2);
                } else {
                    $couponDiscount = round($discountValue, 2);
                }
                $couponDiscount = round(min($couponDiscount, $subtotalAmount), 2);
                $couponCodeUsed = (string)($coupon->code ?? '');
                $couponIdUsed = (int)$coupon->id;
                logLine("Coupon '{$couponCodeUsed}' applied to subscription #{$subId}: -\${$couponDiscount}");
            }
        }
    }

    $amount = round($subtotalAmount - $couponDiscount, 2);
    if ($amount <= 0) {
        $amount = 0.01;
    }

    if ($userId <= 0) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: subscription #{$subId} is missing user id (email: {$email}).",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $card = $cardsRepo->getOne(['id_user' => $userId, 'main_card' => 'yes']);
    if (!$card || empty($card->token)) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: no payment method found for subscription #{$subId} (user #{$userId}).",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $activeProvider = $providersRepo->getActiveProviderForOwner($ownerId);
    $providerType = $activeProvider ? (string)($activeProvider->provider_type ?? '') : '';
    if (!$activeProvider || !in_array($providerType, ['square', 'stripe'], true)) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: no active payment provider configured for owner #{$ownerId}.",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $baseOrder = null;
    $baseOrderId = (int)($sub->id_store_order ?? 0);
    if ($baseOrderId > 0) {
        $baseOrder = $ordersRepo->getById($baseOrderId);
    }

    $orderCreated = $ordersRepo->add([
        'id_owner' => $ownerId,
        'id_user' => $userId,
        'id_cart' => null,
        'public_token' => $ordersRepo->generatePublicToken(),
        'guest_name' => $name !== '' ? $name : ($baseOrder ? (string)($baseOrder->guest_name ?? '') : 'Customer'),
        'guest_email' => $email !== '' ? $email : ($baseOrder ? (string)($baseOrder->guest_email ?? '') : ''),
        'guest_phone' => (string)($sub->phone ?? ($baseOrder ? (string)($baseOrder->guest_phone ?? '') : '')) ?: null,
        'city' => (string)($sub->city ?? ($baseOrder ? (string)($baseOrder->city ?? '') : '')) ?: null,
        'audience_type' => $baseOrder ? ($baseOrder->audience_type ?? null) : null,
        'meal_style' => $baseOrder ? ($baseOrder->meal_style ?? null) : null,
        'pricing_mode' => StoreOrdersRepository::PRICING_SUBSCRIPTION,
        'items_count' => count($items),
        'meals_count' => $mealsCount,
        'subtotal' => $subtotalAmount,
        'discount' => $couponDiscount,
        'total' => $amount,
        'payment_status' => StoreOrdersRepository::PAYMENT_PENDING,
        'status' => StoreOrdersRepository::STATUS_NEW,
        'billing_address_1' => $baseOrder ? ($baseOrder->billing_address_1 ?? null) : null,
        'billing_address_2' => $baseOrder ? ($baseOrder->billing_address_2 ?? null) : null,
        'billing_city' => $baseOrder ? ($baseOrder->billing_city ?? null) : null,
        'billing_state' => $baseOrder ? ($baseOrder->billing_state ?? null) : null,
        'billing_zip' => $baseOrder ? ($baseOrder->billing_zip ?? null) : null,
        'shipping_address_1' => $baseOrder ? ($baseOrder->shipping_address_1 ?? null) : null,
        'shipping_address_2' => $baseOrder ? ($baseOrder->shipping_address_2 ?? null) : null,
        'shipping_city' => $baseOrder ? ($baseOrder->shipping_city ?? null) : null,
        'shipping_state' => $baseOrder ? ($baseOrder->shipping_state ?? null) : null,
        'shipping_zip' => $baseOrder ? ($baseOrder->shipping_zip ?? null) : null,
        'notes' => "Subscription renewal (subscription #{$subId})" . ($couponCodeUsed ? " | coupon: {$couponCodeUsed} (-\${$couponDiscount})" : ''),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    if (!$orderCreated) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: order could not be created for subscription #{$subId}.",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $orderId = $ordersRepo->getLastId();
    $order = $ordersRepo->getById($orderId);
    if (!$order) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: order not found after creation (subscription #{$subId}).",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    foreach ($items as $it) {
        $qty = (int)($it->quantity ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $unit = $pricePerMeal;
        $line = round($unit * $qty, 2);
        $okItem = $orderItemsRepo->add([
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'id_product' => (int)($it->id_product ?? 0),
            'product_name_snapshot' => (string)($it->product_name_snapshot ?? 'Meal'),
            'unit_price' => $unit,
            'pricing_mode' => StoreOrderItemsRepository::PRICING_SUBSCRIPTION,
            'quantity' => $qty,
            'line_total' => $line
        ]);
        if (!$okItem) {
            $failed++;
            adminNotify(
                $ownerId,
                "⚠️ Subscription renewal failed: could not create order items (order #{$orderId}, subscription #{$subId}).",
                LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
            );
            continue 2;
        }
    }

    $paymentSaved = $paymentsRepo->add([
        'id_owner' => $ownerId,
        'id_store_order' => $orderId,
        'id_user' => $userId,
        'payment_method' => $providerType,
        'payment_type' => StorePaymentsRepository::TYPE_SUBSCRIPTION_INITIAL,
        'external_payment_id' => null,
        'external_reference' => null,
        'amount' => $amount,
        'currency' => strtoupper((string)($activeProvider->currency ?? 'USD')),
        'status' => StorePaymentsRepository::STATUS_PENDING,
        'payer_name' => $order->guest_name ?? $name,
        'payer_email' => $order->guest_email ?? $email,
        'raw_response' => null,
        'paid_at' => null
    ]);

    if (!$paymentSaved) {
        $failed++;
        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal failed: payment log could not be created (order #{$orderId}, subscription #{$subId}).",
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $paymentId = $paymentsRepo->getLastId();
    $amountCents = (int)round($amount * 100);

    $chargeResult = null;
    if ($providerType === 'stripe') {
        try {
            $chargeId = $stripe->createChargeV1((string)$card->token, $amount, strtolower((string)($activeProvider->currency ?? 'usd')));
            if ($chargeId) {
                $chargeResult = [
                    'success' => true,
                    'payment_id' => $chargeId,
                    'raw' => null
                ];
            } else {
                $chargeResult = [
                    'success' => false,
                    'message' => 'Payment failed.'
                ];
            }
        } catch (\Throwable $e) {
            $chargeResult = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    } else {
        $squareCustomerIdForCardOnFile = getSquareCardCustomerIdCurl($activeProvider, (string)$card->token);
        $chargeResult = chargeSquarePaymentCurl(
            $activeProvider,
            (string)$card->token,
            $amountCents,
            (string)($order->guest_email ?? $email),
            "Avomeal Subscription Renewal - Subscription #{$subId}",
            $squareCustomerIdForCardOnFile
        );
    }

    if (!($chargeResult['success'] ?? false)) {
        $failed++;
        $paymentsRepo->markAsFailed($paymentId, $chargeResult['raw'] ?? null);
        $ordersRepo->markAsFailed($orderId);

        adminNotify(
            $ownerId,
            "⚠️ Subscription renewal charge failed for subscription #{$subId} (order #{$orderId}): " . (string)($chargeResult['message'] ?? 'Payment failed'),
            LocationUtils::pathFor('panel/planner-hub/store/subscriptions/home')
        );
        continue;
    }

    $paymentsRepo->markAsPaid($paymentId, (string)($chargeResult['payment_id'] ?? null), (string)($chargeResult['raw'] ?? null));
    $ordersRepo->markAsPaid($orderId);

    $nextChargeDate = date('Y-m-d', strtotime('+7 days'));
    $subsRepo->update([
        'id_store_order' => $orderId,
        'last_charge_date' => $today,
        'next_charge_date' => $nextChargeDate,
        'updated_at' => date('Y-m-d H:i:s')
    ], ['id' => $subId]);

    try {
        sendSubscriptionRenewalEmail(
            $ownerId,
            (string)($order->guest_email ?? $email),
            (string)($order->guest_name ?? $name),
            $orderId,
            (string)($order->public_token ?? ''),
            $amount,
            $nextChargeDate
        );
    } catch (\Throwable $e) {
    }

    $successful++;
    logLine("SUCCESS subscription #{$subId} -> order #{$orderId} subtotal={$subtotalAmount} discount={$couponDiscount} charged={$amount}");
}

logLine("Done. processed={$processed}, successful={$successful}, failed={$failed}, skipped={$skipped}");
logLine("=== Store Subscription Renewals completed ===");
