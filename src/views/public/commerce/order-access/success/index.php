<?php

use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\TipsRepository;
use App\Services\OrderAccessSavedPaymentMethodService;

$token = $_GET["token"] ?? null;
$next = $_GET["next"] ?? null;

if (!$token) {
    LocationUtils::redirectInternal("/404");
}

$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded["order_id"])) {
    LocationUtils::redirectInternal("/404");
}

$orderId = intval($decoded["order_id"]);
$orderRepo = new OrdersRepository();
$order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);

if ($order) {
    $order = (object)$order;
}

if (!$order) {
    LocationUtils::redirectInternal("/404");
}

$nextUrl = null;
if ($next === "second") {
    $nextUrl = "order-access/second/?token=" . urlencode($token);
}

$showTipOption = false;
$orderTotal = 0;
$activeProvider = null;
$paymentOwnerId = null;

if (empty($order->id_tip) && empty($next) && !empty($order->id_owner)) {
    $paymentsRepo = new OrdersPaymentsRepository();
    $payments = $paymentsRepo->getAllBy(["id_order" => $orderId]);
    
    $mainPayments = array_filter($payments, function($p) {
        return empty($p->id_suborder) || $p->id_suborder == 0;
    });
    
    $totalPaid = 0;
    foreach ($mainPayments as $p) {
        $totalPaid += (float)$p->amount;
    }
    
    $orderTotal = $orderRepo->calculateTotal($orderId);
    
    if ($totalPaid >= $orderTotal) {
        $paymentProvidersRepo = new PaymentProvidersRepository();
        $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
        $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
        if ($activeProvider && $activeProvider->is_verified && in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true)) {
            $showTipOption = true;
        }
    }
}

$tipsRepo = new TipsRepository();
$suggestedTips = $tipsRepo->getActiveTips();

// Obtener información del cliente
$clientEmail = '';
if (!empty($order->id_client)) {
    $userRepo = new \App\Repositories\UserRepository();
    $client = $userRepo->getOne(["id" => $order->id_client]);
    if ($client && !empty($client->email)) {
        $clientEmail = $client->email;
    }
}

$baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $baseUrl = str_replace('http://', 'https://', $baseUrl);
}

$squareAppId = '';
$squareLocId = '';
$squareEnv = 'sandbox';
$stripePublishableKey = '';
$paypalClientId = '';
$paypalEnvironment = 'sandbox';
$currencyCode = strtoupper($order->currency ?? ($activeProvider ? $activeProvider->currency : null) ?? 'USD');
$paymentProviderName = '';
$savedPaymentViewData = [
    'can_use_saved_payment_methods' => false,
    'saved_payment_methods' => [],
    'supports_future_payment_methods' => false,
    'payment_consent_text' => '',
    'payment_consent_version' => '',
];

if ($activeProvider) {
    if ($activeProvider->provider_type === 'square') {
        $squareAppId = $activeProvider->public_key ?? '';
        $squareLocId = $activeProvider->location_id ?? '';
        $squareEnv = $activeProvider->environment ?? 'sandbox';
    } elseif ($activeProvider->provider_type === 'stripe') {
        $stripePublishableKey = $activeProvider->public_key ?? '';
    } elseif ($activeProvider->provider_type === 'paypal') {
        $paypalClientId = $activeProvider->api_key ?? '';
        $paypalEnvironment = $activeProvider->environment ?? 'sandbox';
    }
    $paymentProviderName = ucfirst($activeProvider->provider_type);
    if ($paymentOwnerId) {
        $savedPaymentViewData = (new OrderAccessSavedPaymentMethodService())->viewDataForOrder($order, (int)$paymentOwnerId, (string)$activeProvider->provider_type);
    }
}

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "token" => $token,
    "next_url" => $nextUrl,
    "show_tip_option" => $showTipOption,
    "order" => $order,
    "order_total" => $orderTotal,
    "suggested_tips" => $suggestedTips,
    "client_email" => $clientEmail,
    "activeProvider" => $activeProvider ? (object)[
        "provider_type" => $activeProvider->provider_type,
        "public_key" => $activeProvider->public_key ?? '',
        "location_id" => $activeProvider->location_id ?? '',
        "environment" => $activeProvider->environment ?? 'sandbox',
        "currency" => $activeProvider->currency ?? 'USD',
    ] : null,
    "currency_code" => $currencyCode,
    "payment_provider_name" => $paymentProviderName,
    "square_application_id" => $squareAppId,
    "square_location_id" => $squareLocId,
    "square_environment" => $squareEnv,
    "stripe_publishable_key" => $stripePublishableKey,
    "paypal_client_id" => $paypalClientId,
    "paypal_environment" => $paypalEnvironment,
    "active_provider_type" => $activeProvider ? $activeProvider->provider_type : null,
    "base_url" => $baseUrl
] + $savedPaymentViewData);
