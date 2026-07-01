<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Services\OrderCalculatorService;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\OrderAccessSavedPaymentMethodService;
use App\Utils\Response;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Services\NotificationService;
use App\Services\PaymentNotificationService;
use App\Utils\Router;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Services\TranslationService;
use App\Utils\ProcessingModal;
use App\Repositories\TipsRepository;

$router = new Router();

// GET
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
    
    $tipAmount = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
        }
    }
    
    $totalAmount = round($base + $tax + $tipAmount, 2);
    
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
    $totalAmount = max($totalAmount - $sumAdvances, 0);

    $paymentRequestLabel = TranslationService::trans('planner_hub.order_full_payment', ['order_id' => $order->id]);
    $totalAmountCents = (int) round($totalAmount * 100);

    $squareAppId = ($activeProvider->provider_type === 'square') ? ($activeProvider->public_key ?? '') : '';
    $squareLocId = ($activeProvider->provider_type === 'square') ? ($activeProvider->location_id ?? '') : '';
    $squareEnv = ($activeProvider->provider_type === 'square') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox';
    $stripePublishableKey = ($activeProvider->provider_type === 'stripe') ? ($activeProvider->public_key ?? '') : '';
    $paypalClientId = ($activeProvider->provider_type === 'paypal') ? ($activeProvider->api_key ?? '') : '';
    $paypalEnvironment = ($activeProvider->provider_type === 'paypal') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox';
    $currencyCode = strtoupper($order->currency ?? ($activeProvider ? $activeProvider->currency : null) ?? 'USD');
    $baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $savedPaymentViewData = $savedPaymentService->viewDataForOrder($order, (int)$paymentOwnerId, (string)$activeProvider->provider_type);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "order" => $order,
        "base_url" => $baseUrl,
        "total_amount" => $totalAmount,
        "total_amount_cents" => $totalAmountCents,
        "square_application_id" => $squareAppId,
        "square_location_id" => $squareLocId,
        "square_environment" => $squareEnv,
        "stripe_publishable_key" => $stripePublishableKey,
        "paypal_client_id" => $paypalClientId,
        "paypal_environment" => $paypalEnvironment,
        "active_provider_type" => $activeProvider->provider_type ?? '',
        "currency_code" => $currencyCode,
        "payment_request_label" => $paymentRequestLabel,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => TranslationService::trans('planner_hub.processing_payment'),
            "message" => TranslationService::trans('planner_hub.we_are_confirming_payment_do_not_close')
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ] + $savedPaymentViewData);
});

// POST
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
    
    $tipAmount = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
        }
    }
    
    $totalAmount = round($base + $tax + $tipAmount, 2);
    
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
    $totalAmount = max($totalAmount - $sumAdvances, 0);

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
    $customerEmail = strtolower(trim($_POST["customer_email"] ?? ""));
    $billingAddress = trim($_POST["billing_address"] ?? "");
    $billingZip = trim((string)($_POST["billing_zip"] ?? ""));

    if ((!$cardToken && $savedPaymentMethodId <= 0) || !$customerEmail) {
        $logDir = \App\Utils\LocationUtils::getRootLocation() . '/.logs';
        $logFile = $logDir . '/app_error_' . date('Y-m-d') . '.log';
        if (is_dir($logDir)) {
            $msg = "\n[order-access/full POST] Missing payment data. order_id=" . ($order->id ?? '') . " has_token=" . ($cardToken ? 'yes' : 'no') . " has_email=" . ($customerEmail !== '' ? 'yes' : 'no') . " provider=" . ($activeProvider->provider_type ?? '') . "\n";
            @file_put_contents($logFile, date('c') . $msg, FILE_APPEND);
        }
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.missing_payment_data')
        ]);
    }

    $paymentRequestLabel = TranslationService::trans('planner_hub.order_full_payment', ['order_id' => $order->id]);
    $provider = PaymentProviderFactory::create($activeProvider);
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $chargeResult = $savedPaymentService->chargeFromPost($provider, $activeProvider, $order, (int)$paymentOwnerId, $totalAmount, [
        'note' => $paymentRequestLabel,
        'reference_id' => 'VNV-341' . $order->id,
        'customer_email' => $customerEmail,
        'customer_name' => $customerName,
        'billing_address' => $billingAddress,
        'billing_zip' => $billingZip,
        'source' => 'order_access_full',
        'order_id' => $order->id,
        'payment_type' => 'full',
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
        "amount" => $totalAmount,
        "method" => $activeProvider->provider_type,
        "stripe_charge_id" => $charge->id ?? null,
        "paid_at" => date("Y-m-d H:i:s"),
        "created_at" => date("Y-m-d H:i:s")
    ];
    if (!empty($billingAddress)) {
        $paymentData["billing_address"] = $billingAddress;
    }
    if ($cardBrand) $paymentData["card_brand"] = $cardBrand;
    if ($cardLast4) $paymentData["card_last4"] = $cardLast4;
    if ($cardExpMonth) $paymentData["card_exp_month"] = $cardExpMonth;
    if ($cardExpYear) $paymentData["card_exp_year"] = $cardExpYear;

    $paymentRepo->add($paymentData);

    $orderRepo->update(["status_workflow" => "INVOICE_PAID"], ["id" => $orderId]);

    $statusRepo = new OrdersStatusHistoryRepository();
    $statusRepo->add([
        "id_order" => $orderId,
        "status" => "INVOICE_PAID",
        "action_type" => $activeProvider->provider_type . '_payment',
        "note" => TranslationService::trans('planner_hub.client_completed_full_payment'),
        "created_by" => 0,
        "created_at" => date("Y-m-d H:i:s")
    ]);

    PaymentNotificationService::generatePaymentNotifications($orderId);

    $providerName = $activeProvider->provider_type === 'stripe' ? 'Stripe' : ($activeProvider->provider_type === 'square' ? 'Square' : $activeProvider->provider_type);
    try {
        $docRepo = new DocumentsLogsRepository();
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($order->id, null, (float)$totalAmount, $providerName, TranslationService::trans('planner_hub.full_payment'));
        error_log("Receipt PDF generated successfully: " . $receiptPath);
        
        $docRepo->add([
            "id_order" => $order->id,
            "id_user" => $order->id_client,
            "doc_type" => "pay_full",
            "file_path" => $receiptPath,
            "hash" => hash_file("sha256", $receiptPath),
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "extra" => json_encode(["order_id" => $order->id, "charge_id" => $charge->id ?? null, "provider" => $activeProvider->provider_type]),
        ]);
        error_log("Receipt saved to document_logs successfully");
    } catch (\Throwable $e) {
        error_log("Failed to generate order payment receipt: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }

    try {
        $recipients = [$order->id_owner];
        if (!empty($order->id_user_client)) {
            $recipients[] = $order->id_user_client;
        }

        NotificationService::sendToUsers(
            $recipients,
            '💳 ' . TranslationService::trans('planner_hub.payment_received'),
            TranslationService::trans('planner_hub.payment_processed_notification', [
                'amount' => number_format($totalAmount, 2),
                'order_id' => $order->id
            ])
        );
    } catch (Exception $e) {
    }

    LocationUtils::redirectInternal("/order-access/success/?token=" . urlencode($token));
});

$router->run();



