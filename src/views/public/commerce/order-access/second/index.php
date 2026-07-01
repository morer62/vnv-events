<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\Connection;
use App\Repositories\TipsRepository;
use App\Services\OrderCalculatorService;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\OrderAccessSavedPaymentMethodService;
use App\Utils\Response;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Services\NotificationService;
use App\Services\PaymentNotificationService;
use App\Utils\Router;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Services\TranslationService;
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

    $eventDateTs = strtotime((string)($order->event_date ?? ''));
    $todayTs = strtotime(date('Y-m-d'));
    if ($eventDateTs !== false && $eventDateTs < $todayTs) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "This event date has already passed. Payments are no longer available."
        ]);
    }

    $paymentProvidersRepo = new PaymentProvidersRepository();
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
    if (!$activeProvider || !$activeProvider->is_verified || !in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true)) {
        LocationUtils::redirectInternal("/404");
    }

    // Calcular total usando precios variables reales
    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
    $serviceRepo = new \App\Repositories\OrdersServiceRepository();
    
    $assigned = $assignedRepo->getAllWithoutOwner(["id_order" => $order->id]);
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
    $paymentRequestLabel = TranslationService::trans('planner_hub.order_final_payment', ['order_id' => $order->id]);
    $secondAmountCents = (int) round($secondAmount * 100);
    
    error_log("[SECOND_PAYMENT][GET] Debug: total={$total}, tipAmount={$tipAmount}, sumAdvances={$sumAdvances}, sumPaid={$sumPaid}, remainingBalance={$remainingBalance}, secondPercent={$secondPercent}, secondAmount={$secondAmount}");

    $squareAppId = ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->public_key ?? '') : '';
    $squareLocId = ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->location_id ?? '') : '';
    $squareEnv = ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox';
    $stripePublishableKey = ($activeProvider && $activeProvider->provider_type === 'stripe') ? ($activeProvider->public_key ?? '') : '';
    $paypalClientId = ($activeProvider && $activeProvider->provider_type === 'paypal') ? ($activeProvider->api_key ?? '') : '';
    $paypalEnvironment = ($activeProvider && $activeProvider->provider_type === 'paypal') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox';
    $currencyCode = strtoupper($order->currency ?? ($activeProvider ? $activeProvider->currency : null) ?? 'USD');
    $baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $savedPaymentViewData = $savedPaymentService->viewDataForOrder($order, (int)$paymentOwnerId, (string)$activeProvider->provider_type);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "order" => $order,
        "base_url" => $baseUrl,
        "second_payment_amount" => $secondAmount,
        "square_application_id" => $squareAppId,
        "square_location_id" => $squareLocId,
        "square_environment" => $squareEnv,
        "stripe_publishable_key" => $stripePublishableKey,
        "paypal_client_id" => $paypalClientId,
        "paypal_environment" => $paypalEnvironment,
        "active_provider_type" => $activeProvider->provider_type ?? '',
        "currency_code" => $currencyCode,
        "payment_request_label" => $paymentRequestLabel,
        "total_amount_cents" => $secondAmountCents,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => TranslationService::trans('planner_hub.processing_payment'),
            "message" => TranslationService::trans('planner_hub.we_are_confirming_payment')
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ] + $savedPaymentViewData);
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
            "error" => TranslationService::trans('planner_hub.no_orders_found')
        ]);
    }

    $eventDateTs = strtotime((string)($order->event_date ?? ''));
    $todayTs = strtotime(date('Y-m-d'));
    if ($eventDateTs !== false && $eventDateTs < $todayTs) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "This event date has already passed. Payments are no longer available."
        ]);
    }

    $paymentProvidersRepo = new PaymentProvidersRepository();
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
    if (!$activeProvider || !$activeProvider->is_verified || !in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true)) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.payment_system_not_configured')
        ]);
    }

    // Calcular total usando precios variables reales
    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
    $serviceRepo = new \App\Repositories\OrdersServiceRepository();
    
    $assigned = $assignedRepo->getAllWithoutOwner(["id_order" => $order->id]);
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
            "error" => TranslationService::trans('planner_hub.amount_cannot_exceed_second_payment', ['amount' => number_format($secondAmount, 2)])
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
    $savedPaymentMethodId = (int)($_POST["saved_payment_method_id"] ?? 0);
    $customerEmail = trim($_POST["customer_email"] ?? "");
    $billingAddress = trim($_POST["billing_address"] ?? "");
    $billingZip = trim((string)($_POST["billing_zip"] ?? ""));

    if ((!$cardToken && $savedPaymentMethodId <= 0) || !$customerEmail) {
        $logDir = \App\Utils\LocationUtils::getRootLocation() . '/.logs';
        $logFile = $logDir . '/app_error_' . date('Y-m-d') . '.log';
        if (is_dir($logDir)) {
            $msg = "\n[order-access/second POST] Missing payment data. order_id=" . ($order->id ?? '') . " has_token=" . ($cardToken ? 'yes' : 'no') . " has_email=" . ($customerEmail !== '' ? 'yes' : 'no') . " has_zip=" . ($billingZip !== '' ? 'yes' : 'no') . " provider=" . ($activeProvider->provider_type ?? '') . "\n";
            @file_put_contents($logFile, date('c') . $msg, FILE_APPEND);
        }
        return Response::createResponse(json_encode([
            "success" => false,
            "error" => TranslationService::trans('planner_hub.missing_payment_data')
        ]));
    }

    $paymentRequestLabel = TranslationService::trans('planner_hub.order_final_installment', ['order_id' => $order->id]);
    $provider = PaymentProviderFactory::create($activeProvider);
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $chargeResult = $savedPaymentService->chargeFromPost($provider, $activeProvider, $order, (int)$paymentOwnerId, $amountInput, [
        'note' => $paymentRequestLabel,
        'reference_id' => 'VNV-341' . $order->id,
        'customer_email' => $customerEmail,
        'customer_name' => $customerName,
        'billing_address' => $billingAddress,
        'billing_zip' => $billingZip,
        'source' => 'order_access_second',
        'order_id' => $order->id,
        'payment_type' => 'second',
    ]);
    $charge = $chargeResult['charge'] ?? false;

    if ($charge === false) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => $chargeResult['error'] ?? TranslationService::trans('planner_hub.payment_could_not_be_processed')
        ]);
    }
    if (isset($charge->status) && $charge->status === 'payment_failed') {
        $errorMessage = $charge->_error_details['message'] ?? TranslationService::trans('planner_hub.payment_failed');
        return TemplateResponse::render(__DIR__ . "/error.twig", ["error" => $errorMessage]);
    }
    if (empty($charge->paid) && (empty($charge->status) || !in_array($charge->status, ['completed', 'succeeded', 'COMPLETED'], true))) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.payment_was_not_completed', ['status' => $charge->status ?? 'unknown'])
        ]);
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

    $paymentRepo = new OrdersPaymentsRepository();
    $paymentData = [
        "id_order" => $orderId,
        "id_suborder" => null,
        "is_suborder" => 0,
        "amount" => $amountInput,
        "method" => $activeProvider->provider_type,
        "stripe_charge_id" => $charge->id ?? null,
        "paid_at" => date("Y-m-d H:i:s"),
        "created_at" => date("Y-m-d H:i:s")
    ];
    if ($cardBrand) $paymentData["card_brand"] = $cardBrand;
    if ($cardLast4) $paymentData["card_last4"] = $cardLast4;
    if ($cardExpMonth) $paymentData["card_exp_month"] = $cardExpMonth;
    if ($cardExpYear) $paymentData["card_exp_year"] = $cardExpYear;
    if ($billingZip !== '') {
        if (!str_contains((string)$billingAddress, $billingZip)) {
            $paymentData["billing_address"] = trim($billingAddress . " " . $billingZip);
        } else {
            $paymentData["billing_address"] = $billingAddress;
        }
    } elseif (!empty($billingAddress)) {
        $paymentData["billing_address"] = $billingAddress;
    }

    $paymentRepo->add($paymentData);

    $orderRepo->update(["status_workflow" => "INVOICE_PAID"], ["id" => $orderId]);

    $statusRepo = new OrdersStatusHistoryRepository();
    $statusRepo->add([
        "id_order" => $orderId,
        "status" => "INVOICE_PAID",
        "action_type" => $activeProvider->provider_type . '_payment',
        "note" => TranslationService::trans('planner_hub.client_paid_final_installment'),
        "created_by" => 0,
        "created_at" => date("Y-m-d H:i:s")
    ]);

    // Generar notificaciones de pagos
    PaymentNotificationService::generatePaymentNotifications($orderId);

    // Generar recibo PDF y guardar en document_logs
    try {
        $docRepo = new DocumentsLogsRepository();
        $providerName = $activeProvider->provider_type === 'stripe' ? 'Stripe' : ($activeProvider->provider_type === 'square' ? 'Square' : $activeProvider->provider_type);
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($order->id, null, (float)$amountInput, $providerName, TranslationService::trans('planner_hub.final_installment_payment'));
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
            '💳 ' . TranslationService::trans('planner_hub.payment_received'),
            TranslationService::trans('planner_hub.payment_processed_notification', [
                'amount' => number_format($amountInput, 2),
                'order_id' => $order->id
            ])
        );
    } catch (Exception $e) {
    }

    LocationUtils::redirectInternal("/order-access/success/?token=" . urlencode($token));
});

$router->run();
