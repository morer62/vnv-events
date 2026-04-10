<?php

use App\Repositories\PaymentProvidersRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrdersRepository;
use App\Repositories\StorePaymentsRepository;
use App\Repositories\StoreSubscriptionItemsRepository;
use App\Repositories\StoreSubscriptionsRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function getSquareApiBaseUrl(string $environment): string
{
    $env = strtolower($environment ?: 'sandbox');
    return $env === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';
}

function chargeSquareStoredCard(
    object $provider,
    string $cardId,
    int $amountCents,
    string $email,
    string $note,
    ?string $customerId = null
): array
{
    $accessToken = trim((string)($provider->api_key ?? ''));
    $locationId = trim((string)($provider->location_id ?? ''));
    $currency = strtoupper((string)($provider->currency ?? 'USD'));

    if ($accessToken === '' || $locationId === '' || $cardId === '') {
        return [
            'success' => false,
            'message' => 'Square stored card payment cannot be processed.'
        ];
    }

    $url = getSquareApiBaseUrl((string)($provider->environment ?? 'sandbox')) . '/v2/payments';
    $payload = [
        'source_id' => $cardId,
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
    if ($customerId) {
        $payload['customer_id'] = $customerId;
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
            'message' => 'Square connection error.',
            'raw' => $curlError
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

    $errorMessage = 'Square payment failed.';
    if (!empty($data['errors'][0]['detail'])) {
        $errorMessage = $data['errors'][0]['detail'];
    }

    return [
        'success' => false,
        'message' => $errorMessage,
        'raw' => $response
    ];
}

function getSquareCardCustomerId(object $provider, string $cardId): ?string
{
    $accessToken = trim((string)($provider->api_key ?? ''));
    if ($accessToken === '' || trim($cardId) === '') {
        return null;
    }

    $url = getSquareApiBaseUrl((string)($provider->environment ?? 'sandbox')) . '/v2/cards/' . rawurlencode($cardId);
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
    return !empty($data['card']['customer_id']) ? (string)$data['card']['customer_id'] : null;
}

function searchSquareCustomerIdByEmail(object $provider, string $email): ?string
{
    $accessToken = trim((string)($provider->api_key ?? ''));
    if ($accessToken === '' || trim($email) === '') {
        return null;
    }

    $url = getSquareApiBaseUrl((string)($provider->environment ?? 'sandbox')) . '/v2/customers/search';
    $payload = [
        'query' => [
            'filter' => [
                'email_address' => [
                    'exact' => $email
                ]
            ]
        ]
    ];

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
    curl_close($ch);

    if (!($httpCode >= 200 && $httpCode < 300)) {
        return null;
    }

    $data = json_decode((string)$response, true);
    return !empty($data['customers'][0]['id']) ? (string)$data['customers'][0]['id'] : null;
}

function chargeStripeStoredCard(object $provider, string $customerToken, int $amountCents, string $email, string $note): array
{
    $secretKey = trim((string)($provider->api_key ?? ''));
    $currency = strtolower((string)($provider->currency ?? 'usd'));

    if ($secretKey === '' || $customerToken === '') {
        return [
            'success' => false,
            'message' => 'Stripe stored card payment cannot be processed.'
        ];
    }

    try {
        \Stripe\Stripe::setApiKey($secretKey);
        $charge = \Stripe\Charge::create([
            'amount' => $amountCents,
            'currency' => $currency,
            'customer' => $customerToken,
            'receipt_email' => $email,
            'description' => $note
        ]);

        return [
            'success' => true,
            'payment_id' => $charge->id ?? null,
            'reference' => $charge->balance_transaction ?? null,
            'raw' => json_encode($charge)
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $repo = new StoreSubscriptionsRepository();

    $subscriptions = $repo->getAllBy(['id_owner' => $ownerId], [], 300);

    usort($subscriptions, function ($a, $b) {
        return (int)($b->id ?? 0) <=> (int)($a->id ?? 0);
    });

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "subscriptions" => $subscriptions ?: []
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $action = trim($_POST['action'] ?? '');
    $subscriptionId = (int)($_POST['subscription_id'] ?? 0);

    if ($action !== 'renew_now' || $subscriptionId <= 0) {
        MessageUtil::setMessage('Invalid renewal request.');
        LocationUtils::reload();
    }

    $subscriptionsRepo = new StoreSubscriptionsRepository();
    $subscriptionItemsRepo = new StoreSubscriptionItemsRepository();
    $ordersRepo = new StoreOrdersRepository();
    $orderItemsRepo = new StoreOrderItemsRepository();
    $paymentsRepo = new StorePaymentsRepository();
    $userCardsRepo = new UserCardsRepository();
    $providersRepo = new PaymentProvidersRepository();
    $userRepo = new UserRepository();

    $subscription = $subscriptionsRepo->getOne([
        'id' => $subscriptionId,
        'id_owner' => $ownerId
    ]);

    if (!$subscription) {
        MessageUtil::setMessage('Subscription not found.');
        LocationUtils::reload();
    }

    if (strtoupper((string)$subscription->status) !== StoreSubscriptionsRepository::STATUS_ACTIVE) {
        MessageUtil::setMessage('Only ACTIVE subscriptions can be renewed manually.');
        LocationUtils::reload();
    }

    $userId = (int)($subscription->id_user ?? 0);
    if ($userId <= 0 && !empty($subscription->email)) {
        $user = $userRepo->getOneWithoutOwnership(['email' => (string)$subscription->email]);
        if ($user) {
            $userId = (int)$user->id;
            $subscriptionsRepo->assignUser((int)$subscription->id, $userId);
        }
    }

    if ($userId <= 0) {
        MessageUtil::setMessage('Renewal failed: subscription has no linked user.');
        LocationUtils::reload();
    }

    $mainCard = $userCardsRepo->getMainCardByUserId($userId);
    if (!$mainCard || empty($mainCard->token)) {
        MessageUtil::setMessage('Renewal failed: customer has no main saved card.');
        LocationUtils::reload();
    }

    $provider = $providersRepo->getActiveProviderForOwner($ownerId);
    $providerType = strtolower((string)($provider->provider_type ?? ''));
    if (!$provider || !in_array($providerType, ['square', 'stripe'], true)) {
        MessageUtil::setMessage('Renewal failed: active payment provider is missing.');
        LocationUtils::reload();
    }

    $subscriptionItems = $subscriptionItemsRepo->getBySubscription((int)$subscription->id);
    if (!$subscriptionItems || count($subscriptionItems) === 0) {
        MessageUtil::setMessage('Renewal failed: subscription has no items.');
        LocationUtils::reload();
    }

    $mealsCount = max(0, (int)($subscription->meals_count ?? 0));
    if ($mealsCount <= 0) {
        $mealsCount = $subscriptionItemsRepo->getMealsCount((int)$subscription->id);
    }
    if ($mealsCount <= 0) {
        MessageUtil::setMessage('Renewal failed: invalid meals count.');
        LocationUtils::reload();
    }

    $pricePerMeal = round((float)($subscription->price_per_meal ?? 0), 2);
    $total = round($pricePerMeal * $mealsCount, 2);
    if ($total <= 0) {
        MessageUtil::setMessage('Renewal failed: invalid renewal amount.');
        LocationUtils::reload();
    }

    $amountCents = (int)round($total * 100);
    $note = 'Manual subscription renewal - Subscription #' . (int)$subscription->id;

    if ($providerType === 'stripe') {
        $paymentResponse = chargeStripeStoredCard(
            $provider,
            (string)$mainCard->token,
            $amountCents,
            (string)$subscription->email,
            $note
        );
    } else {
        $squareCardId = (string)$mainCard->token;
        $squareCustomerId = getSquareCardCustomerId($provider, $squareCardId);
        if (!$squareCustomerId) {
            $squareCustomerId = searchSquareCustomerIdByEmail($provider, (string)$subscription->email);
        }

        if (!$squareCustomerId) {
            MessageUtil::setMessage('Renewal failed: Square customer_id could not be resolved for saved card.');
            LocationUtils::reload();
        }

        $paymentResponse = chargeSquareStoredCard(
            $provider,
            $squareCardId,
            $amountCents,
            (string)$subscription->email,
            $note,
            $squareCustomerId
        );
    }

    if (!($paymentResponse['success'] ?? false)) {
        MessageUtil::setMessage('Renewal charge failed: ' . (string)($paymentResponse['message'] ?? 'unknown error'));
        LocationUtils::reload();
    }

    $orderCreated = $ordersRepo->add([
        'id_owner' => $ownerId,
        'id_user' => $userId,
        'id_cart' => null,
        'public_token' => $ordersRepo->generatePublicToken(),
        'guest_name' => (string)($subscription->full_name ?? ''),
        'guest_email' => (string)($subscription->email ?? ''),
        'guest_phone' => (string)($subscription->phone ?? ''),
        'city' => (string)($subscription->city ?? ''),
        'audience_type' => null,
        'meal_style' => null,
        'pricing_mode' => StoreOrdersRepository::PRICING_SUBSCRIPTION,
        'items_count' => count($subscriptionItems),
        'meals_count' => $mealsCount,
        'subtotal' => $total,
        'discount' => 0.00,
        'total' => $total,
        'payment_status' => StoreOrdersRepository::PAYMENT_PENDING,
        'status' => StoreOrdersRepository::STATUS_NEW,
        'notes' => 'Manual renewal from planner hub. Source subscription #' . (int)$subscription->id,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    if (!$orderCreated) {
        MessageUtil::setMessage('Renewal charged, but order creation failed.');
        LocationUtils::reload();
    }

    $orderId = $ordersRepo->getLastId();
    foreach ($subscriptionItems as $item) {
        $qty = max(1, (int)($item->quantity ?? 0));
        $lineTotal = round($pricePerMeal * $qty, 2);
        $ok = $orderItemsRepo->add([
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'id_product' => (int)$item->id_product,
            'product_name_snapshot' => (string)$item->product_name_snapshot,
            'unit_price' => $pricePerMeal,
            'pricing_mode' => StoreOrdersRepository::PRICING_SUBSCRIPTION,
            'quantity' => $qty,
            'line_total' => $lineTotal
        ]);

        if (!$ok) {
            MessageUtil::setMessage('Renewal charged, but order items could not be saved.');
            LocationUtils::reload();
        }
    }

    $paymentSaved = $paymentsRepo->add([
        'id_owner' => $ownerId,
        'id_store_order' => $orderId,
        'id_user' => $userId,
        'payment_method' => $providerType,
        'payment_type' => StorePaymentsRepository::TYPE_RECOVERY,
        'external_payment_id' => $paymentResponse['payment_id'] ?? null,
        'external_reference' => $paymentResponse['reference'] ?? null,
        'amount' => $total,
        'currency' => strtoupper((string)($provider->currency ?? 'USD')),
        'status' => StorePaymentsRepository::STATUS_PAID,
        'payer_name' => (string)($subscription->full_name ?? ''),
        'payer_email' => (string)($subscription->email ?? ''),
        'raw_response' => $paymentResponse['raw'] ?? null,
        'paid_at' => date('Y-m-d H:i:s')
    ]);

    if (!$paymentSaved) {
        MessageUtil::setMessage('Renewal charged, but payment log could not be saved.');
        LocationUtils::reload();
    }

    $ordersRepo->markAsPaid($orderId);
    $subscriptionsRepo->registerCharge(
        (int)$subscription->id,
        date('Y-m-d'),
        date('Y-m-d', strtotime('+7 days'))
    );

    MessageUtil::setMessage('Subscription renewed successfully. New order #' . $orderId . ' was created.');
    LocationUtils::reload();
});

$router->run();
