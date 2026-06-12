<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\Connection;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\TranslationService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.method_not_allowed')]);
    exit;
}

$token = $_POST['token'] ?? $_GET['token'] ?? null;
$paymentType = $_POST['payment_type'] ?? null; // 'first' | 'second' | 'full'

if (!$token) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.token_required')]);
    exit;
}

$secret = $_ENV['VNV_SECRET_KEY'] ?? 'mySuperSecretKey';
$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded['suborder_id'], $decoded['user_id'], $decoded['exp'], $decoded['hash'])) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_token')]);
    exit;
}

$hashCheck = hash_hmac('sha256', json_encode([
    'suborder_id' => $decoded['suborder_id'],
    'user_id' => $decoded['user_id'],
    'exp' => $decoded['exp']
]), $secret);
if (!hash_equals((string)$decoded['hash'], $hashCheck) || time() > $decoded['exp']) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_expired_token')]);
    exit;
}

$suborderId = (int)$decoded['suborder_id'];
$suborderRepo = new OrdersSuborderRepository();
$orderRepo = new OrdersRepository();
$suborder = $suborderRepo->getByIdWithoutOwnershipCheck($suborderId);
if (!$suborder) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.suborder_not_found')]);
    exit;
}

$parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
if ($parentOrder) {
    $parentOrder = (object)$parentOrder;
}
if (!$parentOrder || empty($parentOrder->id_owner)) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.no_orders_found')]);
    exit;
}

$paymentProvidersRepo = new PaymentProvidersRepository();
$paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($parentOrder);
$activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
if (!$activeProvider || $activeProvider->provider_type !== 'paypal' || !$activeProvider->is_verified) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.paypal_not_configured')]);
    exit;
}

if (!in_array($paymentType, ['first', 'second', 'full'], true)) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_payment_type')]);
    exit;
}

// Calcular total suborden (misma lógica que suborder first/second/full)
$suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
$suborderServices = $suborderServicesRepo->getServicesWithDetails($suborder->id);
$subtotalCalculated = 0;
foreach ($suborderServices as $service) {
    $subtotalCalculated += $service->quantity * $service->actual_price;
}
$discountValue = (float)($suborder->discount_value ?? 0);
$discount = max(0, $discountValue);
$base = max($subtotalCalculated - $discount, 0);
$taxRate = (float)($suborder->tax_percertance ?? 0);
$tax = $base * ($taxRate / 100);
$total = $base + $tax;

try {
    $db = new Connection();
    $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
    $db->bind(':id', $suborder->id);
    $db->execute();
    $row = $db->fetchAll()[0] ?? null;
    $sumAdvances = (float)($row->total_advanced ?? 0);
} catch (\Throwable $e) {
    $sumAdvances = 0;
}
$total = max($total - $sumAdvances, 0);

$paymentsRepo = new OrdersPaymentsRepository();
$payments = $paymentsRepo->getAllBy(['id_suborder' => $suborder->id]);
$totalPaid = 0;
foreach ($payments as $p) {
    $totalPaid += (float)$p->amount;
}
$remaining = max($total - $totalPaid, 0);

if ($paymentType === 'first') {
    $firstPercent = (float)($suborder->payment_split_percent_1 ?? 50);
    $amount = round($remaining * $firstPercent / 100, 2);
    $label = TranslationService::trans('planner_hub.suborder_first_payment', ['suborder_id' => $suborder->id]);
} elseif ($paymentType === 'second') {
    $amount = round($remaining, 2);
    $label = TranslationService::trans('planner_hub.suborder_final_payment', ['suborder_id' => $suborder->id]);
} else {
    $amount = round($remaining, 2);
    $label = TranslationService::trans('planner_hub.suborder_full_payment', ['suborder_id' => $suborder->id]);
}

if ($amount < 0.01) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_amount')]);
    exit;
}

try {
    $provider = PaymentProviderFactory::create($activeProvider);
    if (!method_exists($provider, 'createOrder')) {
        echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.provider_not_support_createorder')]);
        exit;
    }
    $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost/vnv-venue';
    $returnUrl = rtrim($baseUrl, '/') . '/order-access/suborder?token=' . urlencode($token) . '&paypal_approved=1';
    $cancelUrl = rtrim($baseUrl, '/') . '/order-access/suborder?token=' . urlencode($token);
    $orderResult = $provider->createOrder((float)$amount, [
        'description' => $label,
        'return_url' => $returnUrl,
        'cancel_url' => $cancelUrl,
        'brand_name' => 'VNV Suborder',
    ]);
    if ($orderResult === false || empty($orderResult->id)) {
        echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.failed_create_paypal_order')]);
        exit;
    }
    echo json_encode(['success' => true, 'orderId' => $orderResult->id]);
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
