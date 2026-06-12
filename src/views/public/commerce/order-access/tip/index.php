<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\OrderAccessSavedPaymentMethodService;
use App\Services\TipReceiptPdfGenerator;
use App\Services\ConfigService;
use App\Services\TranslationService;
use App\Utils\Response;
use App\Utils\LocationUtils;

try {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    ConfigService::init();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.initialization_error') . ' ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_request_method')]);
    exit;
}

$token = $_POST["token"] ?? null;
$cardToken = $_POST["customer_token"] ?? null;
$savedPaymentMethodId = (int)($_POST["saved_payment_method_id"] ?? 0);
$customerEmail = trim($_POST["customer_email"] ?? "");
$tipAmount = floatval($_POST["tip_amount"] ?? 0);

// Validar token primero
if (!$token) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_token')]);
    exit;
}

$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded["order_id"])) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.invalid_token')]);
    exit;
}

$orderId = intval($decoded["order_id"]);
$userId = intval($decoded["user_id"]);

$orderRepo = new OrdersRepository();
$order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);

if ($order) {
    $order = (object)$order;
}

if (!$order || empty($order->id_owner)) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.no_orders_found')]);
    exit;
}

// Obtener información del cliente desde la base de datos para obtener el email si no se proporcionó
$userRepo = new \App\Repositories\UserRepository();
$client = $userRepo->getOneWithoutOwnership(["id" => $order->id_client]);

// Si no se proporcionó email en el POST, obtenerlo del cliente en la BD
if (empty($customerEmail) && $client && !empty($client->email)) {
    $customerEmail = trim($client->email);
}

$paymentProvidersRepo = new PaymentProvidersRepository();
$paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);

if (!$activeProvider || !$activeProvider->is_verified || !in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true)) {
    echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.payment_provider_not_configured')]);
    exit;
}

// Validar campos requeridos después de intentar obtener el email del cliente
if ((!$cardToken && $savedPaymentMethodId <= 0) || empty($customerEmail) || $tipAmount < 1) {
    $missingFields = [];
    if (!$cardToken) $missingFields[] = 'customer_token';
    if (empty($customerEmail)) $missingFields[] = 'customer_email';
    if ($tipAmount < 1) $missingFields[] = 'tip_amount';
    
    error_log("[TIP PAYMENT] Missing fields: " . implode(', ', $missingFields));
    error_log("[TIP PAYMENT] POST data: " . json_encode($_POST));
    error_log("[TIP PAYMENT] Client email from DB: " . ($client && isset($client->email) ? $client->email : 'not found'));
    error_log("[TIP PAYMENT] Order ID: " . $orderId . ", Client ID: " . ($order->id_client ?? 'not found'));
    
    echo json_encode([
        'success' => false, 
        'error' => TranslationService::trans('planner_hub.missing_required_fields') . ' (' . implode(', ', $missingFields) . ')'
    ]);
    exit;
}

// Construir nombre completo del cliente
$customerName = "";
if ($client) {
    $nameParts = [];
    if (!empty($client->name)) $nameParts[] = trim($client->name);
    if (!empty($client->lastname)) $nameParts[] = trim($client->lastname);
    $customerName = implode(" ", $nameParts);
}

try {
    $provider = PaymentProviderFactory::create($activeProvider);
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $chargeResult = $savedPaymentService->chargeFromPost($provider, $activeProvider, $order, (int)$paymentOwnerId, $tipAmount, [
        'note' => TranslationService::trans('planner_hub.gratuity_tip_order', ['order_id' => $order->id]),
        'reference_id' => 'VNV-341' . $order->id,
        'customer_email' => $customerEmail,
        'customer_name' => $customerName,
        'source' => 'order_access_tip',
        'order_id' => $order->id,
        'payment_type' => 'tip',
    ]);
    $charge = $chargeResult['charge'] ?? false;

    if ($charge === false) {
        echo json_encode(['success' => false, 'error' => $chargeResult['error'] ?? TranslationService::trans('planner_hub.payment_could_not_be_processed')]);
        exit;
    }
    if (isset($charge->status) && $charge->status === 'payment_failed') {
        echo json_encode(['success' => false, 'error' => $charge->_error_details['message'] ?? TranslationService::trans('planner_hub.payment_failed')]);
        exit;
    }
    if (empty($charge->paid) && (empty($charge->status) || !in_array($charge->status, ['completed', 'succeeded', 'COMPLETED'], true))) {
        echo json_encode(['success' => false, 'error' => TranslationService::trans('planner_hub.payment_was_not_completed', ['status' => $charge->status ?? 'unknown'])]);
        exit;
    }

    $cardBrand = null;
    $cardLast4 = null;
    $cardExpMonth = null;
    $cardExpYear = null;
    if (isset($charge->raw)) {
        $raw = $charge->raw;
        if (isset($raw->payment_method_details->card)) {
            $cardBrand = $raw->payment_method_details->card->brand ?? null;
            $cardLast4 = $raw->payment_method_details->card->last4 ?? null;
            $cardExpMonth = $raw->payment_method_details->card->exp_month ?? null;
            $cardExpYear = $raw->payment_method_details->card->exp_year ?? null;
        } elseif (is_object($raw) && method_exists($raw, 'getCardDetails') && $raw->getCardDetails() && method_exists($raw->getCardDetails(), 'getCard') && $raw->getCardDetails()->getCard()) {
            $cardObj = $raw->getCardDetails()->getCard();
            $cardBrand = method_exists($cardObj, 'getCardBrand') ? $cardObj->getCardBrand() : null;
            $cardLast4 = method_exists($cardObj, 'getLast4') ? $cardObj->getLast4() : null;
        }
    }

    $cardDetails = [
        'brand' => $cardBrand,
        'last4' => $cardLast4,
        'exp_month' => $cardExpMonth,
        'exp_year' => $cardExpYear
    ];

    $providerName = $activeProvider->provider_type === 'stripe' ? 'Stripe' : ($activeProvider->provider_type === 'square' ? 'Square' : $activeProvider->provider_type);
    $pdfPath = TipReceiptPdfGenerator::generateAndSave(
        $orderId,
        $tipAmount,
        $providerName,
        $cardDetails
    );

    $paymentsRepo = new OrdersPaymentsRepository();
    $paymentsRepo->add([
        'id_order' => $orderId,
        'amount' => $tipAmount,
        'method' => $activeProvider->provider_type,
        'stripe_charge_id' => $charge->id ?? null,
        'paid_at' => date('Y-m-d H:i:s'),
        'card_brand' => $cardBrand,
        'card_last4' => $cardLast4,
        'card_exp_month' => $cardExpMonth,
        'card_exp_year' => $cardExpYear,
        'payment_concept' => TranslationService::trans('planner_hub.tip_receipt'),
        'receipt_pdf' => $pdfPath
    ]);
    
    if ($pdfPath) {
        $docRepo = new DocumentsLogsRepository();
        
        $isUrl = filter_var($pdfPath, FILTER_VALIDATE_URL) !== false;
        $hash = null;
        if (!$isUrl && file_exists($pdfPath)) {
            $hash = hash_file("sha256", $pdfPath);
        }
        
        $docRepo->add([
            'id_order' => $orderId,
            'id_user' => $userId,
            'file_path' => $pdfPath,
            'doc_type' => 'tip_receipt',
            'hash' => $hash,
            'ip' => $_SERVER["REMOTE_ADDR"] ?? null,
            'user_agent' => $_SERVER["HTTP_USER_AGENT"] ?? null,
            'extra' => json_encode(['tip_amount' => $tipAmount, 'payment_id' => $charge->id])
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => TranslationService::trans('planner_hub.tip_processed_successfully'),
        'pdf_path' => $pdfPath
    ]);
    exit;
} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

