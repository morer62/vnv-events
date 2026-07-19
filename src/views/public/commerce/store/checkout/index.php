<?php

use App\Repositories\StoreCartsRepository;
use App\Repositories\StoreCartItemsRepository;
use App\Repositories\StoreOrdersRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StorePaymentsRepository; 
use App\Repositories\StoreCouponsRepository;
use App\Repositories\StoreCouponRedemptionsRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserBillingInfoRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\StoreCustomerService;
use App\Services\EmailServiceFactory;
use App\Services\StripeService;
use App\Services\LoginService;
use App\Services\StoreCouponService;
use App\Services\ClientPaymentMethodService;
use App\Utils\LocationUtils;
use App\Utils\AvomealContext;

$router = new Router();

function getStoreOwnerId(): int
{
    return AvomealContext::ownerId();
}

function getSquareApiBaseUrl(string $environment): string
{
    $env = strtolower($environment ?: 'sandbox');
    return $env === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';
}

function formatOrderAddressBlock(
    string $address1,
    string $address2,
    string $city,
    string $state,
    string $zip
): string {
    $parts = [];
    if (trim($address1) !== '') $parts[] = trim($address1);
    if (trim($address2) !== '') $parts[] = trim($address2);
    if (trim($city) !== '') $parts[] = trim($city);
    $stateZip = trim(trim($state) . (trim($zip) !== '' ? (' ' . trim($zip)) : ''));
    if ($stateZip !== '') $parts[] = $stateZip;
    return htmlspecialchars(implode(', ', $parts));
}

function sendCheckoutOrderDetailsEmail(
    int $ownerId,
    string $recipientEmail,
    string $guestName,
    int $orderId,
    string $publicToken,
    array $cartItems,
    float $subtotal,
    float $discount,
    float $total,
    string $paymentMethod,
    string $billingAddress1,
    string $billingAddress2,
    string $billingCity,
    string $billingState,
    string $billingZip,
    string $shippingAddress1,
    string $shippingAddress2,
    string $shippingCity,
    string $shippingState,
    string $shippingZip
): bool {
    $baseUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
    $accessPath = (string)LocationUtils::pathFor("store/order-access?token=" . urlencode($publicToken));
    $isAbsolute = (bool)preg_match('#^https?://#i', $accessPath);
    if ($isAbsolute) {
        $accessUrl = $accessPath;
    } else {
        $accessUrl = $baseUrl . '/' . ltrim($accessPath, '/');
    }

    $rows = '';
    foreach ($cartItems as $item) { 
        $name = htmlspecialchars((string)($item->product_name_snapshot ?? 'Item'));
        $qty = (int)($item->quantity ?? 0);
        $line = number_format((float)($item->line_total ?? 0), 2);
        $rows .= "<tr>
            <td style=\"padding:8px;border-bottom:1px solid #eee;\">{$name}</td>
            <td style=\"padding:8px;border-bottom:1px solid #eee;text-align:center;\">{$qty}</td>
            <td style=\"padding:8px;border-bottom:1px solid #eee;text-align:right;\">$ {$line}</td>
        </tr>";
    }

    $subject = "Order #{$orderId} confirmation";
    $message = '
        <div style="font-family:Arial,sans-serif;color:#222;line-height:1.6;max-width:700px;margin:0 auto;">
            <h2 style="margin-bottom:6px;">Thanks for your purchase!</h2>
            <p style="margin-top:0;">Hi ' . htmlspecialchars($guestName) . ', your payment was processed successfully.</p>
            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin:16px 0;">
                <p style="margin:0;"><strong>Order:</strong> #' . (int)$orderId . '</p>
                <p style="margin:0;"><strong>Payment method:</strong> ' . htmlspecialchars(strtoupper($paymentMethod)) . '</p>
            </div>
            <h3 style="margin-bottom:8px;">Order details</h3>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #ddd;">Item</th>
                        <th style="text-align:center;padding:8px;border-bottom:2px solid #ddd;">Qty</th>
                        <th style="text-align:right;padding:8px;border-bottom:2px solid #ddd;">Total</th>
                    </tr>
                </thead>
                <tbody>' . $rows . '</tbody>
            </table>
            <div style="margin-top:16px;">
                <p style="margin:2px 0;"><strong>Subtotal:</strong> $ ' . number_format($subtotal, 2) . '</p>
                <p style="margin:2px 0;"><strong>Discount:</strong> $ ' . number_format($discount, 2) . '</p>
                <p style="margin:2px 0;font-size:16px;"><strong>Total paid:</strong> $ ' . number_format($total, 2) . '</p>
            </div>
            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:16px;">
                <div style="flex:1;min-width:250px;">
                    <h4 style="margin:0 0 6px;">Billing address</h4>
                    <p style="margin:0;">' . formatOrderAddressBlock($billingAddress1, $billingAddress2, $billingCity, $billingState, $billingZip) . '</p>
                </div>
                <div style="flex:1;min-width:250px;">
                    <h4 style="margin:0 0 6px;">Shipping address</h4>
                    <p style="margin:0;">' . formatOrderAddressBlock($shippingAddress1, $shippingAddress2, $shippingCity, $shippingState, $shippingZip) . '</p>
                </div>
            </div>
            <p style="margin-top:18px;">
                <a href="' . htmlspecialchars($accessUrl) . '" style="display:inline-block;padding:10px 16px;background:#111827;color:#fff;text-decoration:none;border-radius:6px;">
                    View order
                </a>
            </p>
        </div>
    ';

    $result = EmailServiceFactory::sendWithOwnerProvider($ownerId, $recipientEmail, $subject, $message, true);
    return (bool)($result['success'] ?? false);
}

function chargeSquarePayment(
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
        $missing = [];
        if ($accessToken === '') { $missing[] = 'Square access token (api_key)'; }
        if ($locationId === '') { $missing[] = 'Square location_id'; }
        return [
            'success' => false,
            'message' => 'Square is not configured. Missing: ' . implode(', ', $missing) . '.'
        ];
    }

    $url = getSquareApiBaseUrl((string)($provider->environment ?? 'sandbox')) . '/v2/payments';

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
            'message' => 'Payment gateway connection error.',
            'raw' => $curlError
        ];
    }

    $data = json_decode($response, true);

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
    $errorCode = strtoupper((string)($data['errors'][0]['code'] ?? ''));
    if ($errorCode === 'GENERIC_DECLINE') {
        $errorMessage = 'The card was declined by the issuing bank. Please try another card or contact your bank.';
    }

    return [
        'success' => false,
        'message' => $errorMessage,
        'raw' => $response
    ];
}

function createSquareCustomer(object $provider, string $email, string $name, ?string $phone = null): ?array
{
    $accessToken = trim((string)($provider->api_key ?? ''));
    if ($accessToken === '') {
        return null;
    }

    $name = trim($name);
    $parts = preg_split('/\s+/', $name, 2);
    $givenName = trim((string)($parts[0] ?? 'Customer'));
    $familyName = trim((string)($parts[1] ?? ''));

    $url = getSquareApiBaseUrl((string)($provider->environment ?? 'sandbox')) . '/v2/customers';
    $payload = [
        'idempotency_key' => bin2hex(random_bytes(16)),
        'email_address' => $email,
        'given_name' => $givenName,
    ];
    if ($familyName !== '') {
        $payload['family_name'] = $familyName;
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
    curl_close($ch);

    $data = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['customer']['id'])) {
        return $data['customer'];
    }

    return null;
}

function createSquareCardOnFile(object $provider, string $customerId, string $sourceId): ?array
{
    $accessToken = trim((string)($provider->api_key ?? ''));
    if ($accessToken === '' || $customerId === '' || $sourceId === '') {
        return null;
    }

    $url = getSquareApiBaseUrl((string)($provider->environment ?? 'sandbox')) . '/v2/cards';
    $payload = [
        'idempotency_key' => bin2hex(random_bytes(16)),
        'source_id' => $sourceId,
        'card' => [
            'customer_id' => $customerId
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

    $data = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['card']['id'])) {
        return $data['card'];
    }

    return null;
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

    $data = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['customers'][0]['id'])) {
        return (string)$data['customers'][0]['id'];
    }

    return null;
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
    if (!is_array($data) || empty($data['card']['customer_id'])) {
        return null;
    }

    return (string)$data['card']['customer_id'];
}

function chargeStripePayment(object $provider, string $token, int $amountCents, string $email, string $note = ''): array
{
    $secretKey = trim((string)($provider->api_key ?? ''));
    $currency = strtolower((string)($provider->currency ?? 'usd'));

    if ($secretKey === '') {
        return [
            'success' => false,
            'message' => 'Stripe is not configured.'
        ];
    }

    try {
        \Stripe\Stripe::setApiKey($secretKey);
        $charge = \Stripe\Charge::create([
            'amount' => $amountCents,
            'currency' => $currency,
            'source' => $token,
            'receipt_email' => $email,
            'description' => $note,
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

function chargeStripeCustomerPayment(
    object $provider,
    string $customerId,
    int $amountCents,
    string $email,
    string $note = ''
): array {
    $secretKey = trim((string)($provider->api_key ?? ''));
    $currency = strtolower((string)($provider->currency ?? 'usd'));

    if ($secretKey === '') {
        return [
            'success' => false,
            'message' => 'Stripe is not configured.'
        ];
    }

    if (trim($customerId) === '') {
        return [
            'success' => false,
            'message' => 'Stripe customer token is missing.'
        ];
    }

    try {
        \Stripe\Stripe::setApiKey($secretKey);
        $charge = \Stripe\Charge::create([
            'amount' => $amountCents,
            'currency' => $currency,
            'customer' => $customerId,
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

function parseCardExpiration(string $exp): array
{
    $parts = preg_split('/[\/\-]/', trim($exp));
    $month = isset($parts[0]) ? (int)$parts[0] : null;
    $year = isset($parts[1]) ? (int)$parts[1] : null;

    if ($year !== null && $year > 0 && $year < 100) {
        $year += 2000;
    }

    return [
        'month' => $month ?: null,
        'year' => $year ?: null,
    ];
}

$router->get(function () {
    $recoveryToken = trim($_GET['recovery'] ?? '');
    $ownerId = getStoreOwnerId();

    $providersRepo = new PaymentProvidersRepository();
    $activeProvider = $ownerId > 0 ? $providersRepo->getActiveProviderForOwner($ownerId) : null;
    $squareAppId = $_ENV["SQUARE_APPLICATION_ID"] ?? "";
    $squareLocationId = $_ENV["SQUARE_LOCATION_ID"] ?? "";
    $squareEnvironment = $_ENV["SQUARE_ENVIRONMENT"] ?? "sandbox";

    $activeProviderType = $activeProvider ? (string)($activeProvider->provider_type ?? '') : '';

    if ($activeProviderType === 'square') {
        $squareAppId = (string)($activeProvider->public_key ?? $squareAppId);
        $squareLocationId = (string)($activeProvider->location_id ?? $squareLocationId);
        $squareEnvironment = (string)($activeProvider->environment ?? $squareEnvironment);
    }

    $stripePublicKey = $_ENV['STRIPE_PUBLIC'] ?? '';
    if ($activeProviderType === 'stripe') {
        $stripePublicKey = (string)($activeProvider->public_key ?? $stripePublicKey);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "active_provider_type" => $activeProviderType,
        "square_application_id" => $squareAppId,
        "square_location_id" => $squareLocationId,
        "square_environment" => $squareEnvironment,
        "stripe_public_key" => $stripePublicKey,
        "provider_currency" => $activeProvider ? strtoupper((string)($activeProvider->currency ?? 'USD')) : 'USD',
        "recovery_token" => $recoveryToken,
        "has_recovery" => $recoveryToken !== ''
    ]);
});

$router->post(function () {
    header('Content-Type: application/json');

    $ownerId = getStoreOwnerId();
    if ($ownerId <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Store owner could not be resolved. Set STORE_OWNER_ID (or DEFAULT_OWNER_ID) in .env."
        ]);
        return;
    }

    $payload = json_decode(file_get_contents("php://input"), true);

    if (!$payload || !is_array($payload)) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid payload"
        ]);
        return;
    }

    $action = trim($payload['action'] ?? '');
    $paymentTokenType = trim((string)($payload['payment_token_type'] ?? 'new_card'));
    $sessionToken = trim($payload['session_token'] ?? '');
    $recoveryToken = trim($payload['recovery_token'] ?? '');

    $cartsRepo = new StoreCartsRepository();
    $cartItemsRepo = new StoreCartItemsRepository();
    $ordersRepo = new StoreOrdersRepository();
    $orderItemsRepo = new StoreOrderItemsRepository();
    $paymentsRepo = new StorePaymentsRepository(); 
    $storeCouponsRepo = new StoreCouponsRepository();
    $couponRedemptionsRepo = new StoreCouponRedemptionsRepository();
    $userRepo = new UserRepository();
    $couponService = new StoreCouponService();

    $cart = null;

    if ($sessionToken !== '') {
        $cart = $cartsRepo->getBySessionToken($sessionToken, $ownerId);
    }

    if (!$cart && $recoveryToken !== '') {
        $cart = $cartsRepo->getByRecoveryToken($recoveryToken, $ownerId);
    }

    if (!$cart) {
        echo json_encode([
            "success" => false,
            "message" => "Cart not found."
        ]);
        return;
    }

    $cartItems = $cartItemsRepo->getByCart((int)$cart->id);

    if (!$cartItems || count($cartItems) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Your cart is empty."
        ]);
        return;
    }

    if ($action === 'saved_cards') {
        $guestEmailForCards = trim((string)($payload['guest_email'] ?? ($cart->guest_email ?? '')));
        if ($guestEmailForCards === '') {
            echo json_encode([
                "success" => true,
                "cards" => []
            ]);
            return;
        }

        $sessionUser = LoginService::getSession();
        if (!$sessionUser || (int)$sessionUser->getLevel() !== 5) {
            echo json_encode([
                "success" => true,
                "cards" => []
            ]);
            return;
        }

        if (strtolower(trim($sessionUser->getEmail())) !== strtolower(trim($guestEmailForCards))) {
            echo json_encode([
                "success" => true,
                "cards" => []
            ]);
            return;
        }

        $user = $userRepo->getOneWithoutOwnership([
            'email' => $guestEmailForCards
        ]);

        if (
            !$user ||
            (string)($user->level ?? '') !== '5'
        ) {
            echo json_encode([
                "success" => true,
                "cards" => []
            ]);
            return;
        }

        $activeProviderForCards = (new PaymentProvidersRepository())->getActiveProviderForOwner($ownerId);
        $activeProviderTypeForCards = strtolower((string)($activeProviderForCards->provider_type ?? ''));
        $paymentMethodService = new ClientPaymentMethodService();
        $savedMethods = $paymentMethodService->listClientSavedPaymentMethods($ownerId, (int)$user->id);

        $savedMethods = array_values(array_filter($savedMethods ?: [], function ($method) use ($activeProviderTypeForCards) {
            return $activeProviderTypeForCards === ''
                || strtolower((string)($method->payment_provider ?? '')) === $activeProviderTypeForCards;
        }));

        $out = array_map(function ($method) {
            $provider = strtolower((string)($method->payment_provider ?? ''));
            $token = $provider === 'stripe'
                ? (string)($method->provider_customer_id ?? '')
                : (string)($method->provider_payment_method_id ?? $method->provider_reference ?? '');

            return [
                'id' => isset($method->id) ? (int)$method->id : null,
                'brand' => (string)($method->brand ?? ''),
                'last4' => (string)($method->last4 ?? ''),
                'exp' => trim((string)($method->exp_month ?? '') . '/' . (string)($method->exp_year ?? ''), '/'),
                'token' => $token,
                'provider' => $provider,
                'source' => 'client_saved_payment_methods'
            ];
        }, $savedMethods ?: []);

        $cardsRepo = new UserCardsRepository();
        $cards = $cardsRepo->getByUserId((int)$user->id);

        foreach (($cards ?: []) as $c) {
            $out[] = [
                'id' => isset($c->id) ? (int)$c->id : null,
                'brand' => (string)($c->brand ?? ''),
                'last4' => (string)($c->last4 ?? ''),
                'exp' => (string)($c->exp ?? ''),
                'token' => (string)($c->token ?? ''),
                'main_card' => (string)($c->main_card ?? ''),
                'provider' => '',
                'source' => 'user_cards'
            ];
        }

        echo json_encode([
            "success" => true,
            "cards" => $out
        ]);
        return;
    }

    $itemsCount = count($cartItems);
    $mealsCount = 0;
    $subtotal = 0.00;

    foreach ($cartItems as $item) {
        $mealsCount += (int)$item->quantity;
        $subtotal += (float)$item->line_total;
    }

    $subtotal = round($subtotal, 2);
    $discount = round((float)($cart->coupon_discount ?? 0), 2);
    $total = round($subtotal - $discount, 2);
    $minimumOrderAmount = AvomealContext::minimumOrderAmount();
    $couponCodeFromCart = trim((string)($cart->coupon_code ?? ''));
    $couponIdFromCart = (int)($cart->id_coupon ?? 0);

    $baseCartUpdateData = [
        'items_count' => $itemsCount,
        'meals_count' => $mealsCount,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $total,
        'updated_at' => date('Y-m-d H:i:s'),
        'last_activity_at' => date('Y-m-d H:i:s')
    ];

    $cartsRepo->update($baseCartUpdateData, [
        'id' => (int)$cart->id
    ]);

    if ($action === 'apply_coupon') {
        $code = trim((string)($payload['coupon_code'] ?? ''));
        $sessionUser = LoginService::getSession();
        $sessionUserId = $sessionUser ? (int)$sessionUser->getId() : null;
        $sessionEmail = $sessionUser ? (string)$sessionUser->getEmail() : null;
        $candidateEmail = trim((string)($payload['guest_email'] ?? ($cart->guest_email ?? '')));
        $emailForValidation = $candidateEmail !== '' ? $candidateEmail : $sessionEmail;

        if ($code === '') {
            $cartsRepo->update([
                'coupon_code' => null,
                'id_coupon' => null,
                'coupon_discount' => 0,
                'discount' => 0,
                'total' => $subtotal,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => (int)$cart->id]);

            $removeNextCharge = null;
            $pMode = strtoupper(trim((string)($cart->pricing_mode ?? '')));
            if ($pMode === StoreCartsRepository::PRICING_SUBSCRIPTION) {
                $removeNextCharge = $subtotal;
            }

            echo json_encode([
                "success" => true,
                "message" => "Coupon removed.",
                "coupon_code" => null,
                "coupon_discount" => 0,
                "discount" => 0,
                "subtotal" => $subtotal,
                "total" => $subtotal,
                "next_charge_total" => $removeNextCharge
            ]);
            return;
        }

        $couponResult = $couponService->validateAndCalculate(
            $ownerId,
            $code,
            $subtotal,
            (string)$cart->pricing_mode,
            $sessionUserId,
            $emailForValidation
        );

        if (!($couponResult['ok'] ?? false)) {
            echo json_encode([
                "success" => false,
                "message" => (string)($couponResult['message'] ?? 'Invalid coupon.')
            ]);
            return;
        }

        $coupon = $couponResult['coupon'];
        $couponDiscount = round((float)$couponResult['discount'], 2);
        $newTotal = round((float)$couponResult['total'], 2);
        $normalizedCode = (string)$couponResult['code'];

        $cartsRepo->update([
            'coupon_code' => $normalizedCode,
            'id_coupon' => (int)$coupon->id,
            'coupon_discount' => $couponDiscount,
            'discount' => $couponDiscount,
            'total' => $newTotal,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => (int)$cart->id]);

        $applyCouponNextCharge = null;
        $pMode = strtoupper(trim((string)($cart->pricing_mode ?? '')));
        if ($pMode === StoreCartsRepository::PRICING_SUBSCRIPTION) {
            $cpMode = strtoupper((string)($coupon->purchase_mode ?? StoreCouponsRepository::PURCHASE_MODE_PAYG));
            $applyCouponNextCharge = ($cpMode === StoreCouponsRepository::PURCHASE_MODE_SUBSCRIPTION)
                ? $newTotal
                : $subtotal;
        }

        echo json_encode([
            "success" => true,
            "message" => "Coupon applied.",
            "coupon_code" => $normalizedCode,
            "coupon_discount" => $couponDiscount,
            "discount" => $couponDiscount,
            "subtotal" => $subtotal,
            "total" => $newTotal,
            "next_charge_total" => $applyCouponNextCharge
        ]);
        return;
    }

    if ($action === 'summary') {
        $guestNameForSummary = trim((string)($cart->guest_name ?? ''));
        $guestEmailForSummary = trim((string)($cart->guest_email ?? ''));
        $guestPhoneForSummary = trim((string)($cart->guest_phone ?? ''));
        $cartUserId = (int)($cart->id_user ?? 0);

        $summarySession = null;
        try {
            $summarySession = LoginService::getSession();
        } catch (\Throwable $e) {
            $summarySession = null;
        }
        $summaryLoggedInId = ($summarySession && (int)$summarySession->getLevel() === 5)
            ? (int)$summarySession->getId()
            : null;

        if ($summaryLoggedInId) {
            if ($summaryLoggedInId !== $cartUserId) {
                $cartUserId = $summaryLoggedInId;
                $cartsRepo->update([
                    'id_user' => $summaryLoggedInId,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => (int)$cart->id]);
            }
        } elseif ($guestEmailForSummary !== '') {
            $user = StoreCustomerService::findOrCreateLevel5User(
                $ownerId,
                $guestNameForSummary !== '' ? $guestNameForSummary : 'Customer',
                $guestEmailForSummary,
                $guestPhoneForSummary !== '' ? $guestPhoneForSummary : null
            );

            if ($user && StoreCustomerService::wasJustCreated($user)) {
                try {
                    $loginUrl = rtrim($_ENV['APP_URL'] ?? '', '/') . '/login';
                    $temporaryPassword = (string)($user->temporary_password_plain ?? '');
                    $clientName = trim((string)($user->name ?? '') . ' ' . (string)($user->lastname ?? ''));
                    $subject = 'Your VNV Events access credentials';
                    $message = '
                        <div style="font-family: Arial, sans-serif; line-height: 1.6; color:#333;">
                            <h2 style="margin: 0 0 10px;">Welcome</h2>
                            <p>Hello ' . htmlspecialchars($clientName ?: 'Customer') . ',</p>
                            <p>Your account was created for your store cart.</p>
                            <div style="background:#fff; border-left:4px solid #f59e0b; padding: 14px; margin: 18px 0;">
                                <p><strong>Email:</strong> ' . htmlspecialchars($guestEmailForSummary) . '</p>
                                <p><strong>Temporary password:</strong> <code style="font-size:16px; background:#f0f0f0; padding:5px 10px; border-radius:3px;">' . htmlspecialchars($temporaryPassword) . '</code></p>
                            </div>
                            <p><a href="' . htmlspecialchars($loginUrl) . '">Login</a></p>
                        </div>
                    ';
                    EmailServiceFactory::sendWithOwnerProvider($ownerId, $guestEmailForSummary, $subject, $message, true);
                } catch (\Throwable $e) {
                }
            }

            if ($user) {
                $uid = (int)($user->id ?? 0);
                if ($uid > 0 && $uid !== $cartUserId) {
                    $cartUserId = $uid;
                    $cartsRepo->update([
                        'id_user' => $uid,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => (int)$cart->id]);
                }
            }
        }

        $billingAddress1 = '';
        $billingAddress2 = '';
        $billingCity = '';
        $billingState = '';
        $billingZip = '';
        if ($cartUserId > 0) {
            $billingRepo = new UserBillingInfoRepository();
            $billing = $billingRepo->getByUserId($cartUserId);
            if ($billing) {
                $billingAddress1 = trim((string)($billing['billing_address_1'] ?? ''));
                $billingAddress2 = trim((string)($billing['billing_address_2'] ?? ''));
                $billingCity = trim((string)($billing['billing_city'] ?? ''));
                $billingState = trim((string)($billing['billing_state'] ?? ''));
                $billingZip = trim((string)($billing['billing_zip'] ?? ''));
            }
        }

        $detailedItems = $cartItemsRepo->getDetailedByCart((int)$cart->id);
        $pricingMode = strtoupper(trim((string)($cart->pricing_mode ?? '')));

        $nextChargeTotal = null;
        $nextChargeDate = null;
        if ($pricingMode === StoreCartsRepository::PRICING_SUBSCRIPTION) {
            $nextChargeDate = date('Y-m-d', strtotime('+7 days'));

            $nextChargeSubtotal = $subtotal;
            $nextChargeTotal = $nextChargeSubtotal;

            if ($couponCodeFromCart !== '') {
                $sessionUser = LoginService::getSession();
                $couponValidation = $couponService->validateAndCalculate(
                    $ownerId,
                    $couponCodeFromCart,
                    $nextChargeSubtotal,
                    (string)$cart->pricing_mode,
                    $sessionUser ? (int)$sessionUser->getId() : null,
                    (string)($cart->guest_email ?? '')
                );

                if (($couponValidation['ok'] ?? false) && !empty($couponValidation['coupon'])) {
                    $couponObj = $couponValidation['coupon'];
                    $purchaseMode = strtoupper((string)($couponObj->purchase_mode ?? StoreCouponsRepository::PURCHASE_MODE_PAYG));

                    if ($purchaseMode === StoreCouponsRepository::PURCHASE_MODE_SUBSCRIPTION) {
                        $nextChargeTotal = round((float)($couponValidation['total'] ?? $nextChargeSubtotal), 2);
                    }
                }
            }
        }

        echo json_encode([
            "success" => true,
            "cart" => [
                "id" => (int)$cart->id,
                "session_token" => $cart->session_token ?? null,
                "recovery_token" => $cart->recovery_token ?? null,
                "guest_name" => $cart->guest_name,
                "guest_email" => $cart->guest_email,
                "guest_phone" => $cart->guest_phone,
                "city" => $cart->city,
                "audience_type" => $cart->audience_type,
                "meal_style" => $cart->meal_style,
                "pricing_mode" => $cart->pricing_mode,
                "items_count" => $itemsCount,
                "meals_count" => $mealsCount,
                "subtotal" => $subtotal,
                "discount" => $discount,
                "coupon_code" => $couponCodeFromCart ?: null,
                "id_coupon" => $couponIdFromCart > 0 ? $couponIdFromCart : null,
                "coupon_discount" => $discount,
                "total" => $total,
                "next_charge_total" => $nextChargeTotal,
                "next_charge_date" => $nextChargeDate,
                "billing_address_1" => $billingAddress1,
                "billing_address_2" => $billingAddress2,
                "billing_city" => $billingCity,
                "billing_state" => $billingState,
                "billing_zip" => $billingZip,
                "shipping_address_1" => $billingAddress1,
                "shipping_address_2" => $billingAddress2,
                "shipping_city" => $billingCity,
                "shipping_state" => $billingState,
                "shipping_zip" => $billingZip
            ],
            "items" => array_map(function ($item) {
                return [
                    "id" => (int)$item->id,
                    "id_product" => (int)$item->id_product,
                    "product_name_snapshot" => $item->product_name_snapshot,
                    "unit_price" => (float)$item->unit_price,
                    "pricing_mode" => $item->pricing_mode,
                    "quantity" => (int)$item->quantity,
                    "line_total" => (float)$item->line_total,
                    "main_image" => $item->main_image ?? null,
                    "slug" => $item->slug ?? null,
                ];
            }, $detailedItems ?: [])
        ]);
        return;
    }

    if ($action === 'pay' && $total < $minimumOrderAmount) {
        echo json_encode([
            "success" => false,
            "message" => "Avomeal minimum order is $" . number_format($minimumOrderAmount, 2) . ". Please add more items before checkout."
        ]);
        return;
    }

    $providersRepo = new PaymentProvidersRepository();
    $activeProvider = $providersRepo->getActiveProviderForOwner($ownerId);
    $providerType = $activeProvider ? (string)($activeProvider->provider_type ?? '') : '';
    if (!$activeProvider || !in_array($providerType, ['square', 'stripe'], true)) {
        echo json_encode([
            "success" => false,
            "message" => "No active payment provider configured (Square/Stripe)."
        ]);
        return;
    }

    if ($action !== 'pay') {
        echo json_encode([
            "success" => false,
            "message" => "Invalid action."
        ]);
        return;
    }

    $customerToken = trim((string)($payload['customer_token'] ?? $payload['card_token'] ?? ''));
    $savePaymentMethod = filter_var($payload['save_payment_method'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $autoChargeConsent = filter_var($payload['auto_charge_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $savedPaymentMethodId = (int)($payload['saved_payment_method_id'] ?? 0);
    $guestName = trim($payload['guest_name'] ?? ($cart->guest_name ?? ''));
    $guestEmail = trim($payload['guest_email'] ?? ($cart->guest_email ?? ''));
    $guestPhone = trim($payload['guest_phone'] ?? ($cart->guest_phone ?? ''));
    $city = trim($payload['city'] ?? ($cart->city ?? ''));

    $billingAddress1 = trim($payload['billing_address_1'] ?? '');
    $billingAddress2 = trim($payload['billing_address_2'] ?? '');
    $billingCity = trim($payload['billing_city'] ?? '');
    $billingState = trim($payload['billing_state'] ?? '');
    $billingZip = trim($payload['billing_zip'] ?? '');

    $shippingAddress1 = trim($payload['shipping_address_1'] ?? '');
    $shippingAddress2 = trim($payload['shipping_address_2'] ?? '');
    $shippingCity = trim($payload['shipping_city'] ?? '');
    $shippingState = trim($payload['shipping_state'] ?? '');
    $shippingZip = trim($payload['shipping_zip'] ?? '');

    $shippingSameAsBilling = filter_var(
        $payload['shipping_same_as_billing'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    $cardInfo = $payload['card_info'] ?? [];
    if (!is_array($cardInfo)) $cardInfo = [];
    $cardBrand = trim((string)($cardInfo['brand'] ?? $payload['card_brand'] ?? ''));
    $cardLast4 = trim((string)($cardInfo['last4'] ?? $payload['card_last4'] ?? ''));
    $cardExp = trim((string)($cardInfo['exp'] ?? $payload['card_exp'] ?? ''));

    $couponFromValidation = null;

    if ($couponCodeFromCart !== '') {
        $sessionUser = LoginService::getSession();
        $couponValidation = $couponService->validateAndCalculate(
            $ownerId,
            $couponCodeFromCart,
            $subtotal,
            (string)$cart->pricing_mode,
            $sessionUser ? (int)$sessionUser->getId() : null,
            $guestEmail !== '' ? $guestEmail : ($sessionUser ? (string)$sessionUser->getEmail() : null)
        );

        if (!($couponValidation['ok'] ?? false)) {
            echo json_encode([
                "success" => false,
                "message" => "Coupon is no longer valid: " . (string)($couponValidation['message'] ?? 'invalid')
            ]);
            return;
        }

        $couponCodeFromCart = (string)$couponValidation['code'];
        $couponFromValidation = $couponValidation['coupon'];
        $couponIdFromCart = (int)($couponFromValidation->id ?? 0);
        $discount = round((float)$couponValidation['discount'], 2);
        $total = round((float)$couponValidation['total'], 2);
    } else {
        $discount = 0;
        $total = $subtotal;
    }

    if ($total < $minimumOrderAmount) {
        echo json_encode([
            "success" => false,
            "message" => "Avomeal minimum order is $" . number_format($minimumOrderAmount, 2) . ". Please add more items before checkout."
        ]);
        return;
    }

    if ($guestName === '' || $guestEmail === '') {
        echo json_encode([
            "success" => false,
            "message" => "Name and email are required."
        ]);
        return;
    }

    if ($paymentTokenType === 'stored_card') {
        $sessionUser = LoginService::getSession();
        if (!$sessionUser || (int)$sessionUser->getLevel() !== 5) {
            echo json_encode([
                "success" => false,
                "message" => "Please log in to use a saved card."
            ]);
            return;
        }

        if (strtolower(trim($sessionUser->getEmail())) !== strtolower(trim($guestEmail))) {
            echo json_encode([
                "success" => false,
                "message" => "Saved card does not match your account."
            ]);
            return;
        }

        if ($customerToken === '') {
            echo json_encode([
                "success" => false,
                "message" => "Payment token missing."
            ]);
            return;
        }

        $cardsRepo = new UserCardsRepository();
        $sessionCards = $cardsRepo->getByUserId((int)$sessionUser->getId());
        $tokenAllowed = false;
        foreach ($sessionCards ?: [] as $c) {
            if ((string)($c->token ?? '') === $customerToken) {
                $tokenAllowed = true;
                break;
            }
        }

        if (!$tokenAllowed) {
            echo json_encode([
                "success" => false,
                "message" => "Saved card was not found for this account."
            ]);
            return;
        }
    }

    if (
        $billingAddress1 === '' ||
        $billingCity === '' ||
        $billingState === '' ||
        $billingZip === '' ||
        $shippingAddress1 === '' ||
        $shippingCity === '' ||
        $shippingState === '' ||
        $shippingZip === ''
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Billing and shipping address are required."
        ]);
        return;
    }

    if ($shippingSameAsBilling) {
        if (
            $billingAddress1 !== $shippingAddress1 ||
            $billingAddress2 !== $shippingAddress2 ||
            $billingCity !== $shippingCity ||
            $billingState !== $shippingState ||
            $billingZip !== $shippingZip
        ) {
            echo json_encode([
                "success" => false,
                "message" => "Shipping address must match billing address."
            ]);
            return;
        }
    }

    if ($shippingCity !== '') {
        $city = $shippingCity;
    } elseif ($billingCity !== '') {
        $city = $billingCity;
    }

    $shippingAddressFull = trim(implode(', ', array_values(array_filter([
        $shippingAddress1,
        $shippingAddress2,
        $shippingCity,
        trim($shippingState . ' ' . $shippingZip)
    ], function ($v) {
        return trim((string)$v) !== '';
    }))));

    $orderNotes = $shippingAddressFull !== '' ? ('Shipping: ' . $shippingAddressFull) : null;

    if ($customerToken === '') {
        echo json_encode([
            "success" => false,
            "message" => "Payment token missing."
        ]);
        return;
    }

     

    $amountCents = (int)round($total * 100);

    if ($providerType === 'square') {
        $squareCustomerIdForCardOnFile = null;
        if ($paymentTokenType === 'stored_card') {
            $squareCustomerIdForCardOnFile = getSquareCardCustomerId($activeProvider, $customerToken);

            if (!$squareCustomerIdForCardOnFile) {
                $squareCustomerIdForCardOnFile = searchSquareCustomerIdByEmail($activeProvider, $guestEmail);
            }
        }

        $paymentResponse = chargeSquarePayment(
            $activeProvider,
            $customerToken,
            $amountCents,
            $guestEmail,
            "Store Order - Cart #" . $cart->id,
            $squareCustomerIdForCardOnFile
        );
    } else {
        if ($paymentTokenType === 'stored_card') {
            $paymentResponse = chargeStripeCustomerPayment(
                $activeProvider,
                $customerToken,
                $amountCents,
                $guestEmail,
                "Avomeal Order - Cart #" . $cart->id
            );
        } else {
            $paymentResponse = chargeStripePayment(
                $activeProvider,
                $customerToken,
                $amountCents,
                $guestEmail,
                "Avomeal Order - Cart #" . $cart->id
            );
        }
    }

    if (!$paymentResponse['success']) {
        echo json_encode([
            "success" => false,
            "message" => $paymentResponse['message'] ?? 'Payment failed.'
        ]);
        return;
    }

    $activeSession = null;
    try {
        $activeSession = LoginService::getSession();
    } catch (\Throwable $e) {
        $activeSession = null;
    }
    $loggedInUserId = ($activeSession && (int)$activeSession->getLevel() === 5)
        ? (int)$activeSession->getId()
        : null;

    if ($loggedInUserId) {
        $userId = $loggedInUserId;
    } else {
        $user = StoreCustomerService::findOrCreateLevel5User(
            $ownerId,
            $guestName,
            $guestEmail,
            $guestPhone ?: null
        );

        if ($user && StoreCustomerService::wasJustCreated($user)) {
            try {
                $loginUrl = rtrim($_ENV['APP_URL'] ?? '', '/') . '/login';
                $temporaryPassword = (string)($user->temporary_password_plain ?? '');
                $clientName = trim((string)($user->name ?? '') . ' ' . (string)($user->lastname ?? ''));

                $subject = 'Your store access credentials';
                $message = '
                    <div style="font-family: Arial, sans-serif; line-height: 1.6; color:#333;">
                        <h2 style="margin: 0 0 10px;">Welcome</h2>
                        <p>Hello ' . htmlspecialchars($clientName ?: 'Customer') . ',</p>
                        <p>Your account was created for your store purchase.</p>
                        <div style="background:#fff; border-left:4px solid #f59e0b; padding: 14px; margin: 18px 0;">
                            <p><strong>Email:</strong> ' . htmlspecialchars($guestEmail) . '</p>
                            <p><strong>Temporary password:</strong> <code style="font-size:16px; background:#f0f0f0; padding:5px 10px; border-radius:3px;">' . htmlspecialchars($temporaryPassword) . '</code></p>
                        </div>
                        <p>You can log in here:</p>
                        <p>
                            <a href="' . htmlspecialchars($loginUrl) . '" style="display:inline-block; padding: 10px 18px; background:#f59e0b; color:#23160a; text-decoration:none; border-radius:6px; font-weight:700;">
                                Login
                            </a>
                        </p>
                        <p style="color:#666; font-size:12px; margin-top: 18px;">You will be able to change your password after logging in.</p>
                    </div>
                ';

                EmailServiceFactory::sendWithOwnerProvider($ownerId, $guestEmail, $subject, $message, true);
            } catch (\Exception $e) {
            }
        }

        $userId = $user ? (int)$user->id : null;
    }

    if ($userId) {
        $billingRepo = new UserBillingInfoRepository();
        $billingRepo->upsert($userId, [
            'billing_address_1' => $billingAddress1,
            'billing_address_2' => $billingAddress2,
            'billing_city' => $billingCity,
            'billing_state' => $billingState,
            'billing_zip' => $billingZip,
        ]);
    }

    $orderCreated = $ordersRepo->add([
        'id_owner' => $ownerId,
        'id_user' => $userId,
        'id_cart' => (int)$cart->id,
        'public_token' => $ordersRepo->generatePublicToken(),
        'guest_name' => $guestName,
        'guest_email' => $guestEmail,
        'guest_phone' => $guestPhone ?: null,
        'city' => $city ?: null,
        'audience_type' => $cart->audience_type ?: null,
        'meal_style' => $cart->meal_style ?: null,
        'pricing_mode' => $cart->pricing_mode ?: StoreOrdersRepository::PRICING_PAYG,
        'items_count' => $itemsCount,
        'meals_count' => $mealsCount,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'coupon_code' => $couponCodeFromCart !== '' ? $couponCodeFromCart : null,
        'id_coupon' => $couponIdFromCart > 0 ? $couponIdFromCart : null,
        'coupon_discount' => $discount,
        'total' => $total,
        'payment_status' => StoreOrdersRepository::PAYMENT_PENDING,
        'status' => StoreOrdersRepository::STATUS_NEW,
        'billing_address_1' => $billingAddress1 ?: null,
        'billing_address_2' => $billingAddress2 ?: null,
        'billing_city' => $billingCity ?: null,
        'billing_state' => $billingState ?: null,
        'billing_zip' => $billingZip ?: null,
        'shipping_address_1' => $shippingAddress1 ?: null,
        'shipping_address_2' => $shippingAddress2 ?: null,
        'shipping_city' => $shippingCity ?: null,
        'shipping_state' => $shippingState ?: null,
        'shipping_zip' => $shippingZip ?: null,
        'notes' => $orderNotes,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    if (!$orderCreated) {
        echo json_encode([
            "success" => false,
            "message" => "Payment was approved, but the order could not be saved."
        ]);
        return;
    }

    $orderId = $ordersRepo->getLastId();
    $order = $ordersRepo->getOne(['id' => $orderId]);

    foreach ($cartItems as $item) {
        $ok = $orderItemsRepo->add([
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'id_product' => (int)$item->id_product,
            'product_name_snapshot' => $item->product_name_snapshot,
            'unit_price' => (float)$item->unit_price,
            'pricing_mode' => $item->pricing_mode,
            'quantity' => (int)$item->quantity,
            'line_total' => (float)$item->line_total
        ]);

        if (!$ok) {
            echo json_encode([
                "success" => false,
                "message" => "Payment was approved, but the order items could not be saved."
            ]);
            return;
        }
    }

    $paymentSaved = $paymentsRepo->add([
        'id_owner' => $ownerId,
        'id_store_order' => $orderId,
        'id_user' => $userId,
        'payment_method' => $providerType,
        'payment_type' => $cart->pricing_mode === StoreCartsRepository::PRICING_SUBSCRIPTION
            ? StorePaymentsRepository::TYPE_SUBSCRIPTION_INITIAL
            : StorePaymentsRepository::TYPE_FULL,
        'external_payment_id' => $paymentResponse['payment_id'] ?? null,
        'external_reference' => $paymentResponse['reference'] ?? null,
        'amount' => $total,
        'currency' => strtoupper((string)($activeProvider->currency ?? ($_ENV['SQUARE_CURRENCY'] ?? 'USD'))),
        'status' => StorePaymentsRepository::STATUS_PAID,
        'payer_name' => $guestName,
        'payer_email' => $guestEmail,
        'raw_response' => $paymentResponse['raw'] ?? null,
        'paid_at' => date('Y-m-d H:i:s')
    ]);

    if (!$paymentSaved) {
        echo json_encode([
            "success" => false,
            "message" => "Payment was approved, but the payment log could not be saved."
        ]);
        return;
    }

    $ordersRepo->markAsPaid($orderId);
    $ordersRepo->updateStatus($orderId, StoreOrdersRepository::STATUS_PROCESSING);

    if ($userId) {
        $ordersRepo->assignUser($orderId, $userId);
    }

    $paymentId = $paymentsRepo->getLastId();
    if ($paymentId && $userId) {
        $paymentsRepo->update([
            'id_user' => $userId
        ], [
            'id' => $paymentId
        ]);
    }

    $paymentMethodService = new ClientPaymentMethodService();
    if ($paymentTokenType === 'stored_card' && $savedPaymentMethodId > 0 && $autoChargeConsent && $userId) {
        $paymentMethodService->recordFromSuccessfulPayment([
            'id_user_business' => $ownerId,
            'id_client' => $userId,
            'user_id' => $userId,
            'payment_provider' => $providerType,
            'saved_payment_method_id' => $savedPaymentMethodId,
            'save_payment_method' => false,
            'auto_charge_consent' => true,
            'source' => 'store_checkout',
            'related_store_order_id' => $orderId,
            'related_payment_id' => $paymentId ?: null,
            'metadata' => [
                'checkout' => 'stored_card',
                'provider_transaction_id' => $paymentResponse['payment_id'] ?? null,
            ],
        ]);
    }

    $cartsRepo->markAsConverted((int)$cart->id);

    try {
        $session = LoginService::getSession();
        $sessionEmail = $session ? trim((string)$session->getEmail()) : '';
        $recipientEmail = $sessionEmail !== '' ? $sessionEmail : $guestEmail;
        if ($recipientEmail !== '') {
            sendCheckoutOrderDetailsEmail(
                $ownerId,
                $recipientEmail,
                $guestName,
                (int)$orderId,
                (string)($order->public_token ?? ''),
                $cartItems ?: [],
                (float)$subtotal,
                (float)$discount,
                (float)$total,
                $providerType,
                $billingAddress1,
                $billingAddress2,
                $billingCity,
                $billingState,
                $billingZip,
                $shippingAddress1,
                $shippingAddress2,
                $shippingCity,
                $shippingState,
                $shippingZip
            );
        }
    } catch (\Throwable $e) {
        error_log('Store order details email error: ' . $e->getMessage());
    }

    if ($paymentTokenType === 'new_card' && $userId && $providerType === 'stripe' && $cardBrand !== '' && $cardLast4 !== '' && $cardExp !== '' && $customerToken !== '') {
        try {
            $stripeService = new StripeService();
            $stripeCustomerId = $stripeService->createCustomerWithCard($customerToken, $guestEmail);

            if ($stripeCustomerId) {
                $cardsRepo = new UserCardsRepository();
                $main = $cardsRepo->countCards($userId) === 0 ? 'yes' : 'no';

                $cardsRepo->add([
                    'id_user' => $userId,
                    'brand' => $cardBrand,
                    'last4' => $cardLast4,
                    'exp' => $cardExp,
                    'token' => $stripeCustomerId,
                    'main_card' => $main,
                    'billing_zip' => $billingZip,
                    'billing_address_1' => $billingAddress1,
                    'billing_address_2' => $billingAddress2,
                    'billing_city' => $billingCity,
                    'billing_state' => $billingState
                ]);

                if ($savePaymentMethod || $autoChargeConsent) {
                    $exp = parseCardExpiration($cardExp);
                    $paymentMethodService->recordFromSuccessfulPayment([
                        'id_user_business' => $ownerId,
                        'id_client' => $userId,
                        'user_id' => $userId,
                        'payment_provider' => 'stripe',
                        'provider_customer_id' => $stripeCustomerId,
                        'provider_payment_method_id' => null,
                        'provider_reference' => $paymentResponse['payment_id'] ?? null,
                        'method_type' => 'card',
                        'brand' => $cardBrand,
                        'last4' => $cardLast4,
                        'exp_month' => $exp['month'],
                        'exp_year' => $exp['year'],
                        'billing_name' => $guestName,
                        'billing_email' => $guestEmail,
                        'is_default' => $main === 'yes',
                        'source' => 'store_checkout',
                        'save_payment_method' => $savePaymentMethod || $autoChargeConsent,
                        'auto_charge_consent' => $autoChargeConsent,
                        'related_store_order_id' => $orderId,
                        'related_payment_id' => $paymentId ?: null,
                        'metadata' => [
                            'provider_transaction_id' => $paymentResponse['payment_id'] ?? null,
                            'legacy_user_card' => true,
                        ],
                    ]);
                }
            }
        } catch (\Exception $e) {
        }
    }

    if ($paymentTokenType === 'new_card' && $userId && $providerType === 'square' && $customerToken !== '') {
        try {
            $squareRaw = json_decode((string)($paymentResponse['raw'] ?? ''), true);
            $rawCard = $squareRaw['payment']['card_details']['card'] ?? [];

            if ($cardBrand === '' && !empty($rawCard['card_brand'])) {
                $cardBrand = (string)$rawCard['card_brand'];
            }
            if ($cardLast4 === '' && !empty($rawCard['last_4'])) {
                $cardLast4 = (string)$rawCard['last_4'];
            }
            if ($cardExp === '' && !empty($rawCard['exp_month']) && !empty($rawCard['exp_year'])) {
                $cardExp = (string)$rawCard['exp_month'] . '/' . (string)$rawCard['exp_year'];
            }

            $squareCustomer = createSquareCustomer($activeProvider, $guestEmail, $guestName, $guestPhone ?: null);
            if ($squareCustomer && !empty($squareCustomer['id'])) {
                $paymentIdForCard = (string)($paymentResponse['payment_id'] ?? '');
                $squareCard = createSquareCardOnFile($activeProvider, (string)$squareCustomer['id'], $paymentIdForCard);

                if ($squareCard && !empty($squareCard['id'])) {
                    if ($cardBrand === '' && !empty($squareCard['card_brand'])) {
                        $cardBrand = (string)$squareCard['card_brand'];
                    }
                    if ($cardLast4 === '' && !empty($squareCard['last_4'])) {
                        $cardLast4 = (string)$squareCard['last_4'];
                    }
                    if ($cardExp === '' && !empty($squareCard['exp_month']) && !empty($squareCard['exp_year'])) {
                        $cardExp = (string)$squareCard['exp_month'] . '/' . (string)$squareCard['exp_year'];
                    }

                    $cardsRepo = new UserCardsRepository();
                    $main = $cardsRepo->countCards($userId) === 0 ? 'yes' : 'no';

                    $cardsRepo->add([
                        'id_user' => $userId,
                        'brand' => $cardBrand !== '' ? $cardBrand : 'square',
                        'last4' => $cardLast4 !== '' ? $cardLast4 : '0000',
                        'exp' => $cardExp !== '' ? $cardExp : 'NA',
                        'token' => (string)$squareCard['id'],
                        'main_card' => $main,
                        'billing_zip' => $billingZip,
                        'billing_address_1' => $billingAddress1,
                        'billing_address_2' => $billingAddress2,
                        'billing_city' => $billingCity,
                        'billing_state' => $billingState
                    ]);

                    if ($savePaymentMethod || $autoChargeConsent) {
                        $exp = parseCardExpiration($cardExp);
                        $paymentMethodService->recordFromSuccessfulPayment([
                            'id_user_business' => $ownerId,
                            'id_client' => $userId,
                            'user_id' => $userId,
                            'payment_provider' => 'square',
                            'provider_customer_id' => (string)$squareCustomer['id'],
                            'provider_payment_method_id' => (string)$squareCard['id'],
                            'provider_reference' => $paymentResponse['payment_id'] ?? null,
                            'method_type' => 'card',
                            'brand' => $cardBrand !== '' ? $cardBrand : 'square',
                            'last4' => $cardLast4 !== '' ? $cardLast4 : null,
                            'exp_month' => $exp['month'],
                            'exp_year' => $exp['year'],
                            'billing_name' => $guestName,
                            'billing_email' => $guestEmail,
                            'is_default' => $main === 'yes',
                            'source' => 'store_checkout',
                            'save_payment_method' => $savePaymentMethod || $autoChargeConsent,
                            'auto_charge_consent' => $autoChargeConsent,
                            'related_store_order_id' => $orderId,
                            'related_payment_id' => $paymentId ?: null,
                            'metadata' => [
                                'provider_transaction_id' => $paymentResponse['payment_id'] ?? null,
                                'legacy_user_card' => true,
                            ],
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
        }
    }

     

    if ($couponIdFromCart > 0 && $couponCodeFromCart !== '' && $discount > 0) {
        $couponRedemptionsRepo->add([
            'id_coupon' => $couponIdFromCart,
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'id_user' => $userId,
            'email' => $guestEmail ?: null,
            'discount_amount' => $discount,
            'redeemed_at' => date('Y-m-d H:i:s')
        ]);

        $storeCouponsRepo->incrementTotalUsesAtomic($couponIdFromCart);
    }

    $successPayload = [
    'order_id' => (int)$orderId,
    'public_token' => (string)$order->public_token,
    'pricing_mode' => 'PAYG',
    'guest_name' => (string)$guestName,
    'total' => (float)$total,
    'email' => (string)$guestEmail,
    'created_at' => date('Y-m-d H:i:s')
    ];

    $encodedSuccessPayload = base64_encode(json_encode($successPayload));

    setcookie(
        'store_payment_success',
        $encodedSuccessPayload,
        [
            'expires' => time() + 900,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );

    echo json_encode([
        "success" => true,
        "message" => "Payment completed successfully.",
        "redirect" => \App\Utils\LocationUtils::pathFor("store/payment-successful") 
    ]);
});

$router->run();
