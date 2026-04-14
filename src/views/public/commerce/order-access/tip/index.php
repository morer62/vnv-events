<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\SquareAccountsRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Services\SquareServiceV2;
use App\Services\TipReceiptPdfGenerator;
use App\Services\ConfigService;
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
    echo json_encode(['success' => false, 'error' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$token = $_POST["token"] ?? null;
$cardToken = $_POST["customer_token"] ?? null;
$customerEmail = trim($_POST["customer_email"] ?? "");
$tipAmount = floatval($_POST["tip_amount"] ?? 0);

if (!$token || !$cardToken || !$customerEmail || $tipAmount < 1) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded["order_id"])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
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
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$accountRepo = new SquareAccountsRepository();
$account = $accountRepo->getByUser((int)$order->id_owner);

if (!$account || empty($account->square_account_id)) {
    echo json_encode(['success' => false, 'error' => 'Square account not configured']);
    exit;
}

// Obtener información del cliente desde la base de datos
$userRepo = new \App\Repositories\UserRepository();
$client = $userRepo->getOne(["id" => $order->id_client]);

// Construir nombre completo del cliente
$customerName = "";
if ($client) {
    $nameParts = [];
    if (!empty($client->name)) $nameParts[] = trim($client->name);
    if (!empty($client->lastname)) $nameParts[] = trim($client->lastname);
    $customerName = implode(" ", $nameParts);
}

try {
    $squareService = new SquareServiceV2();
    
    // Obtener o crear customer en Square
    $customer = $squareService->getCustomerOnConnectedAccount($customerEmail, $account->square_account_id);
    
    if (!$customer) {
        // Crear customer con el token de tarjeta
        $customer = $squareService->createCustomerWithCardOnConnectedAccount(
            $cardToken,
            $customerEmail,
            $customerName ?: 'Customer',
            $account->square_account_id
        );
        
        if (!$customer) {
            echo json_encode(['success' => false, 'error' => 'Failed to create customer']);
            exit;
        }
    }
    
    // Procesar el pago de la propina
    $charge = $squareService->chargeCustomerOnConnectedAccount(
        $customer->getId(),
        $tipAmount,
        $account->square_account_id,
        $cardToken
    );
    
    if (!$charge) {
        echo json_encode(['success' => false, 'error' => 'Failed to process payment']);
        exit;
    }
    
    // Verificar si el pago falló
    if (isset($charge->status) && $charge->status === 'payment_failed') {
        $errorMessage = "Payment failed";
        if (isset($charge->_error_details['message'])) {
            $errorMessage = $charge->_error_details['message'];
        }
        echo json_encode(['success' => false, 'error' => $errorMessage]);
        exit;
    }
    
    // Verificar si el estado del pago no es completado
    if (isset($charge->status) && $charge->status !== 'completed' && $charge->status !== 'approved') {
        echo json_encode(['success' => false, 'error' => 'Payment was not completed. Status: ' . ($charge->status ?? 'unknown')]);
        exit;
    }
    
    // Extraer detalles de la tarjeta desde el pago de Square
    $cardBrand = null;
    $cardLast4 = null;
    $cardExpMonth = null;
    $cardExpYear = null;
    
    if (isset($charge->payment_method_details)) {
        $details = $charge->payment_method_details;
        if (isset($details->card)) {
            $cardBrand = $details->card->brand ?? null;
            $cardLast4 = $details->card->last4 ?? null;
            $cardExpMonth = $details->card->exp_month ?? null;
            $cardExpYear = $details->card->exp_year ?? null;
        }
    }
    
    $cardDetails = [
        'brand' => $cardBrand,
        'last4' => $cardLast4,
        'exp_month' => $cardExpMonth,
        'exp_year' => $cardExpYear
    ];
    
    $pdfPath = TipReceiptPdfGenerator::generateAndSave(
        $orderId,
        $tipAmount,
        'Square',
        $cardDetails
    );
    
    $paymentsRepo = new OrdersPaymentsRepository();
    $paymentsRepo->add([
        'id_order' => $orderId,
        'amount' => $tipAmount,
        'method' => 'square',
        'stripe_charge_id' => $charge->id, // Mantener el nombre del campo por compatibilidad
        'paid_at' => date('Y-m-d H:i:s'),
        'card_brand' => $cardBrand,
        'card_last4' => $cardLast4,
        'card_exp_month' => $cardExpMonth,
        'card_exp_year' => $cardExpYear,
        'payment_concept' => 'Gratuity/Tip',
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
        'message' => 'Tip processed successfully',
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

