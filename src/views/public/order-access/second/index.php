<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Repositories\SquareAccountsRepository;
use App\Repositories\Connection;
use App\Repositories\TipsRepository;
use App\Services\OrderCalculatorService;
use App\Services\SquareServiceV2;
use App\Utils\Response;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Services\NotificationService;
use App\Services\PaymentNotificationService;
use App\Utils\Router;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Utils\ProcessingModal;

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
    
    // Calcular tip solo si existe y está activo (no afecta si no hay tip)
    $tipAmount = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
        }
    }
    
    $total = $base + $tax + $tipAmount;
    
    // Subtract previous advances to calculate real balance
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) { $sumAdvances = 0; }

    // Restar pagos previos registrados (por si ya hubo parcial/full)
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) { $sumPaid = 0; }

        // For second installment, calculate on total balance minus advances and previous payments
        // Second installment should be remaining balance after advances and first payment
    $remainingBalance = max($total - $sumAdvances - $sumPaid, 0);
    
    $secondPercent = $order->payment_split_percent_2 ?? 50;
    $secondAmount = round($remainingBalance, 2);
    $paymentRequestLabel = sprintf("Order VNV-341%s - Final Payment", $order->id);
    $secondAmountCents = (int) round($secondAmount * 100);
    
    error_log("[SECOND_PAYMENT][GET] Debug: total={$total}, tipAmount={$tipAmount}, sumAdvances={$sumAdvances}, sumPaid={$sumPaid}, remainingBalance={$remainingBalance}, secondPercent={$secondPercent}, secondAmount={$secondAmount}");

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "order" => $order,
        "second_payment_amount" => $secondAmount,
        "square_application_id" => $_ENV["SQUARE_APPLICATION_ID"] ?? "",
        "square_location_id" => $_ENV["SQUARE_LOCATION_ID"] ?? "",
        "square_environment" => $_ENV["SQUARE_ENVIRONMENT"] ?? "sandbox",
        "payment_request_label" => $paymentRequestLabel,
        "total_amount_cents" => $secondAmountCents,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => "Processing payment...",
            "message" => "We are confirming your payment. Please wait."
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

$router->post(function () {
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
    
    // Calcular tip solo si existe y está activo (no afecta si no hay tip)
    $tipAmount = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
        }
    }
    
    $total = $base + $tax + $tipAmount;
    
    // Subtract previous advances to calculate real balance
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) { $sumAdvances = 0; }

    // Restar pagos previos registrados (por si ya hubo parcial/full)
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) { $sumPaid = 0; }

        // For second installment, calculate on total balance minus advances and previous payments
        // Second installment should be remaining balance after advances and first payment
    $remainingBalance = max($total - $sumAdvances - $sumPaid, 0);
    
    $secondPercent = $order->payment_split_percent_2 ?? 50;
    $secondAmount = round($remainingBalance, 2);
    
    error_log("================================================");
    error_log("[SECOND_PAYMENT][POST] INICIANDO COBRO DE SEGUNDA CUOTA");
    error_log("[SECOND_PAYMENT][POST] Order ID: {$orderId}");
    error_log("[SECOND_PAYMENT][POST] Total calculado: {$total}");
    error_log("[SECOND_PAYMENT][POST] Advances aplicados: {$sumAdvances}");
    error_log("[SECOND_PAYMENT][POST] Pagos previos (sumPaid): {$sumPaid}");
    error_log("[SECOND_PAYMENT][POST] Saldo restante calculado: {$remainingBalance}");
    error_log("[SECOND_PAYMENT][POST] Segundo pago (secondAmount): {$secondAmount}");
    error_log("================================================");

        // Validate that amount does not exceed second installment
    $amountInput = (float)($_POST["advance_amount"] ?? $secondAmount);
    error_log("[SECOND_PAYMENT][POST] Monto a cobrar (amountInput): {$amountInput}");
    if ($amountInput > $secondAmount) {
        error_log("[SECOND_PAYMENT][POST] Amount exceeds second payment: {$amountInput} > {$secondAmount}");
        return Response::createResponse(json_encode([
            "success" => false,
            "error" => "Amount cannot exceed second payment of $" . number_format($secondAmount, 2)
        ]));
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
    
    // Si no hay nombre en la BD, usar el del POST como fallback
    if (empty($customerName)) {
        $customerName = trim($_POST["customer_name"] ?? "");
    }

    $cardToken = $_POST["customer_token"] ?? null;
    $customerEmail = trim($_POST["customer_email"] ?? "");

    if (!$cardToken || !$customerEmail) {
        return Response::createResponse(json_encode([
            "success" => false,
            "error" => "Missing payment data"
        ]));
    }

    $squareService = new SquareServiceV2();
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

    error_log("[SECOND_PAYMENT][POST] Realizando cargo con Square...");
    error_log("[SECOND_PAYMENT][POST] Customer ID: " . $customer->getId());
    error_log("[SECOND_PAYMENT][POST] Monto a cobrar: {$amountInput}");
    error_log("[SECOND_PAYMENT][POST] Square Account ID: {$account->square_account_id}");
    
    $charge = $squareService->chargeCustomerOnConnectedAccount(
        $customer->getId(),
        $amountInput,
        $account->square_account_id,
        $cardToken
    );

    if (!$charge) {
        error_log("[SECOND_PAYMENT][POST] ERROR: El cargo falló - chargeCustomerOnConnectedAccount retornó null");
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
        error_log("[SECOND_PAYMENT][POST] ERROR: Pago no completado. Status: " . ($charge->status ?? 'unknown'));
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Payment was not completed. Status: " . ($charge->status ?? 'unknown')
        ]);
    }
    
    error_log("[SECOND_PAYMENT][POST] ✅ Cargo exitoso!");
    error_log("[SECOND_PAYMENT][POST] Charge ID: " . ($charge->id ?? 'N/A'));
    error_log("[SECOND_PAYMENT][POST] Charge Status: " . ($charge->status ?? 'N/A'));
    error_log("[SECOND_PAYMENT][POST] Monto cobrado: {$amountInput}");

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

    error_log("[SECOND_PAYMENT][POST] Guardando pago en base de datos...");
    error_log("[SECOND_PAYMENT][POST] Monto a guardar: {$amountInput}");
    
    $paymentRepo = new OrdersPaymentsRepository();
    $paymentData = [
        "id_order" => $orderId,
        "id_suborder" => null, // Asegurar que es NULL para pagos de orden principal
        "is_suborder" => 0, // Asegurar que es 0 para pagos de orden principal
        "amount" => $amountInput,
        "method" => "square",
        "stripe_charge_id" => $charge->id, // Mantener el nombre del campo por compatibilidad
        "paid_at" => date("Y-m-d H:i:s"),
        "created_at" => date("Y-m-d H:i:s")
    ];
    
    if ($cardBrand) $paymentData["card_brand"] = $cardBrand;
    if ($cardLast4) $paymentData["card_last4"] = $cardLast4;
    if ($cardExpMonth) $paymentData["card_exp_month"] = $cardExpMonth;
    if ($cardExpYear) $paymentData["card_exp_year"] = $cardExpYear;
    
    $paymentId = $paymentRepo->add($paymentData);
    error_log("[SECOND_PAYMENT][POST] ✅ Pago guardado en BD. Payment ID: " . ($paymentId ?? 'N/A'));
    error_log("[SECOND_PAYMENT][POST] Monto guardado: {$amountInput}");

    $orderRepo->update([
        "status_workflow" => "INVOICE_PAID"
    ], ["id" => $orderId]);

    $statusRepo = new OrdersStatusHistoryRepository();
    $statusRepo->add([
        "id_order" => $orderId,
        "status" => "INVOICE_PAID",
        "action_type" => "square_payment",
        "note" => "Client paid final installment.",
        "created_by" => 0,
        "created_at" => date("Y-m-d H:i:s")
    ]);

    // Generar notificaciones de pagos
    PaymentNotificationService::generatePaymentNotifications($orderId);

    // Generar recibo PDF y guardar en document_logs
    try {
        $docRepo = new DocumentsLogsRepository();
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($order->id, null, (float)$secondAmount, 'Square', 'Final Installment Payment');
        error_log("Receipt PDF generated successfully: " . $receiptPath);
        
        $docRepo->add([
            "id_order" => $order->id,
            "id_user" => $order->id_client,
            "doc_type" => "pay_second",
            "file_path" => $receiptPath,
            "hash" => hash_file("sha256", $receiptPath),
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "extra" => json_encode(["order_id" => $order->id, "charge_id" => $charge->id ?? null, "payment_type" => "second"]),
        ]);
        error_log("Receipt saved to document_logs successfully");
    } catch (\Throwable $e) {
        error_log("Failed to generate payment receipt: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }

    try {
        PaymentNotificationService::generatePaymentNotifications($orderId);

        NotificationService::sendToUsers(
            [$order->id_owner, $order->id_client],
            '💳 Payment Received',
            'A payment of $' . number_format($amountInput, 2) . ' has been processed for order # VNV341' . $order->id
        );
    } catch (Exception $e) {
    }

    LocationUtils::redirectInternal("/order-access/success/?token=" . urlencode($token));
});

$router->run();
