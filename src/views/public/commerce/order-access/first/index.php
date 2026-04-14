<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Repositories\SquareAccountsRepository;
use App\Repositories\TipsRepository;
use App\Services\OrderCalculatorService;
use App\Services\NotificationService;
use App\Services\PaymentNotificationService;
use App\Services\SquareServiceV2;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Response;
use App\Utils\Router;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Utils\ProcessingModal;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$router = new Router();

$router->get(function () {
    $token = $_GET["token"] ?? null;
    if (!$token) LocationUtils::redirectInternal("/404");

    $decoded = json_decode(base64_decode($token), true);
    if (!$decoded || !isset($decoded["order_id"])) LocationUtils::redirectInternal("/404");

    $orderId = intval($decoded["order_id"]);
    $orderRepo = new OrdersRepository();
    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if ($order) {
        $order = (object)$order;
    }
    if (!$order) LocationUtils::redirectInternal("/404");

    $accountRepo = new SquareAccountsRepository();
    $account = $accountRepo->getByUser($order->id_owner);
    if (!$account || empty($account->square_account_id)) {
        LocationUtils::redirectInternal("/404");
    }

    // Calcular total usando precios variables reales
    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
    $serviceRepo = new \App\Repositories\OrdersServiceRepository();
    
    $assigned = $assignedRepo->getAllBy(["id_order" => $order->id]);
    $subtotalCalculated = 0;
    
    foreach ($assigned as $a) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($a->id_service);
        
        // Usar el precio histórico almacenado (unit_price) si existe
        if (isset($a->unit_price) && $a->unit_price > 0) {
            $unitPrice = $a->unit_price;
        } else {
            // Fallback para órdenes antiguas que no tienen unit_price
            $unitPrice = ($a->is_variable === 'YES' && $a->variable_price !== null) 
                ? $a->variable_price 
                : $service->price;
        }
        
        $subtotalCalculated += $a->quantity * $unitPrice;
    }
    
    // Calculate amounts using real subtotal with variable prices
    $discountType = $order->discount_type ?? 'amount';
    $discountValue = $order->discount_value ?? 0;
    
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $descuento = $discountValue;
    
    $base = max($subtotalCalculated - $descuento, 0);
    $taxRate = $order->tax_percentage ?? 0;
    $tax = $base * ($taxRate / 100);
    
    $tipAmount = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
        }
    }
    
    $total = $base + $tax + $tipAmount;
    
    // Subtract registered advances
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
    }
    $total = max($total - $sumAdvances, 0);
    
    $firstPercent = $order->payment_split_percent_1 ?? 50;
    
        // For first installment, calculate on remaining balance after advances
    $firstAmount = round($total * $firstPercent / 100, 2);
    $paymentRequestLabel = sprintf("Order VNV-341%s - First Payment", $order->id);
    $firstAmountCents = (int) round($firstAmount * 100);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "order" => $order,
        "first_payment_amount" => $firstAmount,
        "square_application_id" => $_ENV["SQUARE_APPLICATION_ID"] ?? "",
        "square_location_id" => $_ENV["SQUARE_LOCATION_ID"] ?? "",
        "square_environment" => $_ENV["SQUARE_ENVIRONMENT"] ?? "sandbox",
        "payment_request_label" => $paymentRequestLabel,
        "total_amount_cents" => $firstAmountCents,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => "Processing first payment...",
            "message" => "We are confirming your payment. Please wait."
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

$router->post(function () {

    $squareService = new SquareServiceV2();
    $token = $_POST["token"] ?? null;
    $decoded = json_decode(base64_decode($token), true);
    if (!$decoded || !isset($decoded["order_id"])) return Response::createResponse("Invalid token");

    $orderId = intval($decoded["order_id"]);
    $orderRepo = new OrdersRepository();
    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if ($order) {
        $order = (object)$order;
    }
    if (!$order) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Order not found"
        ]);
    }

    $accountRepo = new SquareAccountsRepository();
    $account = $accountRepo->getByUser($order->id_owner);
    if (!$account || empty($account->square_account_id)) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Owner can receive payments"
        ]);
    }

    // Calcular total usando precios variables reales
    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
    $serviceRepo = new \App\Repositories\OrdersServiceRepository();
    
    $assigned = $assignedRepo->getAllBy(["id_order" => $order->id]);
    $subtotalCalculated = 0;
    
    foreach ($assigned as $a) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($a->id_service);
        
        // Usar el precio histórico almacenado (unit_price) si existe
        if (isset($a->unit_price) && $a->unit_price > 0) {
            $unitPrice = $a->unit_price;
        } else {
            // Fallback para órdenes antiguas que no tienen unit_price
            $unitPrice = ($a->is_variable === 'YES' && $a->variable_price !== null) 
                ? $a->variable_price 
                : $service->price;
        }
        
        $subtotalCalculated += $a->quantity * $unitPrice;
    }
    
    // Calculate amounts using real subtotal with variable prices
    $discountType = $order->discount_type ?? 'amount';
    $discountValue = $order->discount_value ?? 0;
    
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $descuento = $discountValue;
    
    $base = max($subtotalCalculated - $descuento, 0);
    $taxRate = $order->tax_percentage ?? 0;
    $tax = $base * ($taxRate / 100);
    
    $tipAmount = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
        }
    }
    
    $total = $base + $tax + $tipAmount;
    
    // Subtract registered advances
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
    }
    $total = max($total - $sumAdvances, 0);
    
    $firstPercent = $order->payment_split_percent_1 ?? 50;
    $firstAmount = round($total * $firstPercent / 100, 2);

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
    
    // Si no hay nombre en la BD, usar el del POST como fallback
    if (empty($customerName)) {
        $customerName = trim($_POST["customer_name"] ?? "");
    }

    $cardToken = $_POST["customer_token"] ?? null;
    $customerEmail = trim($_POST["customer_email"] ?? "");

    if (!$cardToken || !$customerEmail) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Missing payment data"
        ]);
    }

    $customer = $squareService->getCustomerOnConnectedAccount($customerEmail, $account->square_account_id);

    if (!$customer) {
        // Si el customer no existe, crearlo con el token
        $customer = $squareService->createCustomerWithCardOnConnectedAccount(
            $cardToken,
            $customerEmail,
            $customerName,
            $account->square_account_id
        );
        
        if (!$customer) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Failed to create customer"
            ]);
        }
    }

    $charge = $squareService->chargeCustomerOnConnectedAccount(
        $customer->getId(),
        $firstAmount,
        $account->square_account_id,
        $cardToken
    );

    if (!$charge) {
        error_log("[SQUARE PAYMENT] Failed to create charge - chargeCustomerOnConnectedAccount returned null");
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Failed to create charge. Please check the server logs for details."
        ]);
    }
    
    // Verificar si el pago falló (objeto de error)
    if (isset($charge->status) && $charge->status === 'payment_failed') {
        $errorMessage = "Payment failed";
        if (isset($charge->_error_details['message'])) {
            $errorMessage = $charge->_error_details['message'];
        }
        error_log("[SQUARE PAYMENT] Payment failed: " . json_encode($charge->_error_details ?? []));
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => $errorMessage
        ]);
    }
    
    // Verificar si el estado del pago no es completado
    if (isset($charge->status) && $charge->status !== 'completed' && $charge->status !== 'approved') {
        error_log("[SQUARE PAYMENT] Payment not completed. Status: " . ($charge->status ?? 'unknown'));
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Payment was not completed. Status: " . ($charge->status ?? 'unknown')
        ]);
    }

    // Extraer detalles de la tarjeta desde el pago de Square
    $cardBrand = null;
    $cardLast4 = null;
    $cardExpMonth = null;
    $cardExpYear = null;
    
    if (isset($charge->payment_method_details)) {
        $details = $charge->payment_method_details;
        if (isset($details->card)) {
            $cardBrand = $details->card->card_brand ?? null;
            $cardLast4 = $details->card->last_4 ?? null;
            $cardExpMonth = $details->card->exp_month ?? null;
            $cardExpYear = $details->card->exp_year ?? null;
        }
    }

    $paymentRepo = new OrdersPaymentsRepository();
    $paymentData = [
        "id_order" => $orderId,
        "id_suborder" => null, // Asegurar que es NULL para pagos de orden principal
        "is_suborder" => 0, // Asegurar que es 0 para pagos de orden principal
        "amount" => $firstAmount,
        "method" => "square",
        "stripe_charge_id" => $charge->id, // Mantener el nombre del campo por compatibilidad
        "paid_at" => date("Y-m-d H:i:s"),
        "created_at" => date("Y-m-d H:i:s")
    ];
    
    if ($cardBrand) $paymentData["card_brand"] = $cardBrand;
    if ($cardLast4) $paymentData["card_last4"] = $cardLast4;
    if ($cardExpMonth) $paymentData["card_exp_month"] = $cardExpMonth;
    if ($cardExpYear) $paymentData["card_exp_year"] = $cardExpYear;
    
    $paymentRepo->add($paymentData);

    $orderRepo->update([
        "status_workflow" => "INVOICE_PARTIAL"
    ], ["id" => $orderId]);

    $statusRepo = new OrdersStatusHistoryRepository();
    $statusRepo->add([
        "id_order" => $orderId,
        "status" => "INVOICE_PARTIAL",
        "action_type" => "square_payment",
        "note" => "Client paid first installment.",
        "created_by" => 0,
        "created_at" => date("Y-m-d H:i:s")
    ]);

    // Generar notificaciones de pagos
    PaymentNotificationService::generatePaymentNotifications($orderId);

    // Generar recibo PDF y guardar en document_logs
    try {
        $docRepo = new DocumentsLogsRepository();
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($order->id, null, (float)$firstAmount, 'Square', 'First Installment Payment');
        error_log("Receipt PDF generated successfully: " . $receiptPath);
        
        $docRepo->add([
            "id_order" => $order->id,
            "id_user" => $order->id_client,
            "doc_type" => "pay_first",
            "file_path" => $receiptPath,
            "hash" => hash_file("sha256", $receiptPath),
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "extra" => json_encode(["order_id" => $order->id, "charge_id" => $charge->id ?? null, "payment_type" => "first"]),
        ]);
        error_log("Receipt saved to document_logs successfully");
    } catch (\Throwable $e) {
        error_log("Failed to generate payment receipt: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }

    try {
        PaymentNotificationService::generatePaymentNotifications($orderId);

        NotificationService::sendToUsers(
            [$order->id_owner],
            '💳 Payment Received',
            'A payment of $' . number_format($firstAmount, 2) . ' has been processed for order # VNV341' . $order->id
        );
    } catch (Exception $e) {
    }

    LocationUtils::redirectInternal("/order-access/success/?token=" . urlencode($token));
});

$router->run();