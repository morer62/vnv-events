<?php

use App\Repositories\OrdersRepository;
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
$tipAmount = isset($_POST['tip_amount']) ? (float)$_POST['tip_amount'] : null;

if (!$token) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.token_required')]);
    exit;
}

$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded['order_id'])) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_token')]);
    exit;
}

$orderId = (int)$decoded['order_id'];
$orderRepo = new OrdersRepository();
$order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
if ($order) {
    $order = (object)$order;
}
if (!$order || empty($order->id_owner)) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.no_orders_found')]);
    exit;
}

$eventDateTs = strtotime((string)($order->event_date ?? ''));
$todayTs = strtotime(date('Y-m-d'));
if ($eventDateTs !== false && $eventDateTs < $todayTs) {
    echo json_encode(['success' => false, 'error' => 'This event date has already passed. Payments are no longer available.']);
    exit;
}

$paymentProvidersRepo = new PaymentProvidersRepository();
$paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
$activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
if (!$activeProvider || $activeProvider->provider_type !== 'paypal' || !$activeProvider->is_verified) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.paypal_not_configured')]);
    exit;
}

$amount = null;
$label = '';

if ($tipAmount !== null && $tipAmount > 0) {
    $amount = $tipAmount;
    $label = TranslationService::trans('planner_hub.gratuity_tip_order', ['order_id' => $order->id]);
} elseif ($paymentType === 'first' || $paymentType === 'second' || $paymentType === 'full') {
    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
    $serviceRepo = new \App\Repositories\OrdersServiceRepository();
    $assigned = $assignedRepo->getAllWithoutOwner(['id_order' => $order->id]);
    $subtotalCalculated = 0;
    foreach ($assigned as $a) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($a->id_service);
        $unitPrice = (isset($a->unit_price) && $a->unit_price > 0)
            ? $a->unit_price
            : (($a->is_variable === 'YES' && $a->variable_price !== null) ? $a->variable_price : $service->price);
        $subtotalCalculated += $a->quantity * $unitPrice;
    }
    $discountValue = (float)($order->discount_value ?? 0);
    $base = max($subtotalCalculated - $discountValue, 0);
    $taxRate = (float)($order->tax_percentage ?? 0);
    $tax = $base * ($taxRate / 100);
    $tipAmountOrder = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new \App\Repositories\TipsRepository();
        $tip = $tipsRepo->getOne(['id' => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmountOrder = $base * ($tip->percentage / 100);
        }
    }
    $total = $base + $tax + $tipAmountOrder;
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(':id', $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
    }
    $total = max($total - $sumAdvances, 0);

    $paymentsRepo = new OrdersPaymentsRepository();
    $payments = $paymentsRepo->getAllBy(['id_order' => $order->id]);
    $mainPayments = array_filter($payments, function ($p) {
        return empty($p->id_suborder) || $p->id_suborder == 0;
    });
    $totalPaid = 0;
    foreach ($mainPayments as $p) {
        $totalPaid += (float)$p->amount;
    }
    $remaining = max($total - $totalPaid, 0);

    if ($paymentType === 'first') {
        $firstPercent = (float)($order->payment_split_percent_1 ?? 50);
        $amount = round($remaining * $firstPercent / 100, 2);
        $label = TranslationService::trans('planner_hub.order_first_installment', ['order_id' => $order->id]);
    } elseif ($paymentType === 'second') {
        $amount = round($remaining, 2);
        $label = TranslationService::trans('planner_hub.order_remaining_balance', ['order_id' => $order->id]);
    } else {
        $amount = round($remaining, 2);
        $label = TranslationService::trans('planner_hub.order_full_payment_label', ['order_id' => $order->id]);
    }
}

if ($amount === null || $amount < 0.01) {
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
    $returnUrl = rtrim($baseUrl, '/') . '/order-access?token=' . urlencode($token) . '&paypal_approved=1';
    $cancelUrl = rtrim($baseUrl, '/') . '/order-access?token=' . urlencode($token);
    $orderResult = $provider->createOrder((float)$amount, [
        'description' => $label,
        'return_url' => $returnUrl,
        'cancel_url' => $cancelUrl,
        'brand_name' => 'VNV Order',
    ]);
    if ($orderResult === false || empty($orderResult->id)) {
        echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.failed_create_paypal_order')]);
        exit;
    }
    echo json_encode(['success' => true, 'orderId' => $orderResult->id]);
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
