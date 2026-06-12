<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\Connection;
use App\Services\PaymentReceiptPdfGenerator;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\OrderAccessSavedPaymentMethodService;
use App\Services\TranslationService;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Response;
use App\Utils\ProcessingModal;

$router = new \App\Utils\Router();

$router->get(function () {
    $token = $_GET["token"] ?? null;
    if (!$token)
        LocationUtils::redirectInternal("/404");

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

    if (!$decoded || !isset($decoded["suborder_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        LocationUtils::redirectInternal("/404");
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "suborder_id" => $decoded["suborder_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        LocationUtils::redirectInternal("/404");
    }

    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();

    $suborder = $suborderRepo->getByIdWithoutOwnershipCheck($decoded["suborder_id"]);
    if (!$suborder)
        LocationUtils::redirectInternal("/404");

    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    
    if (!$parentOrder)
        LocationUtils::redirectInternal("/404");

    // Calcular total usando precios variables reales
    $suborderServices = $suborderServicesRepo->getServicesWithDetails($suborder->id);
    $subtotalCalculated = 0;
    
    foreach ($suborderServices as $service) {
        $subtotalCalculated += $service->quantity * $service->actual_price;
    }
    
    // Calcular descuento y montos finales (descuento antes de impuestos)
    $discountType = $suborder->discount_type ?? 'amount';
    $discountValue = (float)($suborder->discount_value ?? 0);
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $discount = $discountValue;
    $discount = max(0, $discount);

    $base = max($subtotalCalculated - $discount, 0);
    $taxRate = (float)($suborder->tax_percertance ?? 0);
    $tax = $base * ($taxRate / 100);
    $totalAmount = round($base + $tax, 2);
    
    // Subtract registered advances
    try {
        $db = new Connection();
        $db->query("SELECT * FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $advances = $db->fetchAll();
        error_log("[SUBORDER_FULL][GET] All advances for suborder " . $suborder->id . ": " . json_encode($advances));
        
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
        error_log("[SUBORDER_FULL][GET] Suborder ID: " . $suborder->id . ", Sum Advances: " . $sumAdvances);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
        error_log("[SUBORDER_FULL][GET] Error getting advances: " . $e->getMessage());
    }
    $totalAmount = max($totalAmount - $sumAdvances, 0);
    error_log("[SUBORDER_FULL][GET] Final calculation - Original: " . round($subtotalCalculated + $tax, 2) . ", Advances: " . $sumAdvances . ", Final: " . $totalAmount);

    $paymentRequestLabel = TranslationService::trans('planner_hub.suborder_full_payment', ['suborder_id' => $suborder->id]);

    $paymentProvidersRepo = new PaymentProvidersRepository();
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($parentOrder);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
    if (!$activeProvider || !$activeProvider->is_verified || !in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true)) {
        LocationUtils::redirectInternal("/404");
    }
    $squareAppId = ($activeProvider->provider_type === 'square') ? ($activeProvider->public_key ?? '') : '';
    $squareLocId = ($activeProvider->provider_type === 'square') ? ($activeProvider->location_id ?? '') : '';
    $squareEnv = ($activeProvider->provider_type === 'square') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox';
    $stripePublishableKey = ($activeProvider->provider_type === 'stripe') ? ($activeProvider->public_key ?? '') : '';
    $paypalClientId = ($activeProvider->provider_type === 'paypal') ? ($activeProvider->api_key ?? '') : '';
    $paypalEnvironment = ($activeProvider->provider_type === 'paypal') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox';
    $currencyCode = strtoupper($parentOrder->currency ?? ($activeProvider ? $activeProvider->currency : null) ?? 'USD');
    $baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $savedPaymentViewData = $savedPaymentService->viewDataForOrder($parentOrder, (int)$paymentOwnerId, (string)$activeProvider->provider_type);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "total_amount" => $totalAmount,
        "square_application_id" => $squareAppId,
        "square_location_id" => $squareLocId,
        "square_environment" => $squareEnv,
        "stripe_publishable_key" => $stripePublishableKey,
        "paypal_client_id" => $paypalClientId,
        "paypal_environment" => $paypalEnvironment,
        "active_provider_type" => $activeProvider->provider_type ?? '',
        "currency_code" => $currencyCode,
        "base_url" => $baseUrl,
        "payment_request_label" => $paymentRequestLabel,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => TranslationService::trans('planner_hub.processing_payment'),
            "message" => TranslationService::trans('planner_hub.confirming_payment')
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ] + $savedPaymentViewData);
});

$router->post(function () {
    $token = $_POST["token"] ?? null;
    error_log("Token received: " . ($token ? "YES" : "NO"));
    
    $decoded = json_decode(base64_decode($token), true);
    error_log("Token decoded successfully: " . ($decoded ? "YES" : "NO"));
    
    if (!$decoded || !isset($decoded["suborder_id"])) {
        error_log("Invalid token - missing suborder_id");
        return Response::createResponse(TranslationService::trans('planner_hub.invalid_token'));
    }

    $suborderId = intval($decoded["suborder_id"]);
    error_log("Suborder ID: " . $suborderId);
    
    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();
    $suborder = $suborderRepo->getByIdWithoutOwnershipCheck($suborderId);
    
    if (!$suborder) {
        error_log("Suborder not found for ID: " . $suborderId);
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.suborder_not_found')
        ]);
    }
    error_log("Suborder found: " . $suborder->id);

    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    
    if (!$parentOrder) {
        error_log("Parent order not found for suborder: " . $suborder->id);
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.parent_order_not_found')
        ]);
    }
    $paymentProvidersRepo = new PaymentProvidersRepository();
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($parentOrder);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
    if (!$activeProvider || !$activeProvider->is_verified || !in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true)) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.payment_provider_not_configured')
        ]);
    }

    // Calcular total usando precios variables reales
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $suborderServices = $suborderServicesRepo->getServicesWithDetails($suborderId);
    $subtotalCalculated = 0;
    
    foreach ($suborderServices as $service) {
        $subtotalCalculated += $service->quantity * $service->actual_price;
    }
    
    // Calcular descuento y montos finales (descuento antes de impuestos)
    $discountType = $suborder->discount_type ?? 'amount';
    $discountValue = (float)($suborder->discount_value ?? 0);
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $discount = $discountValue;
    $discount = max(0, $discount);

    $base = max($subtotalCalculated - $discount, 0);
    $taxRate = (float)($suborder->tax_percertance ?? 0);
    $tax = $base * ($taxRate / 100);
    $totalAmount = round($base + $tax, 2);
    
    // Subtract registered advances
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborderId);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
    }
    $totalAmount = max($totalAmount - $sumAdvances, 0);
    
    error_log("Payment calculation - Subtotal: $subtotalCalculated, Tax: $tax, Advances: $sumAdvances, Total: $totalAmount");

    // Obtener información del cliente desde la base de datos
    $userRepo = new \App\Repositories\UserRepository();
    $client = $userRepo->getOne(["id" => $parentOrder->id_client]);
    
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

    if ((!$cardToken && $savedPaymentMethodId <= 0) || !$customerEmail) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.missing_payment_data')
        ]);
    }

    $paymentRequestLabel = TranslationService::trans('planner_hub.suborder_full_payment', ['suborder_id' => $suborder->id]);
    $provider = PaymentProviderFactory::create($activeProvider);
    $savedPaymentService = new OrderAccessSavedPaymentMethodService();
    $chargeResult = $savedPaymentService->chargeFromPost($provider, $activeProvider, $parentOrder, (int)$paymentOwnerId, $totalAmount, [
        'note' => $paymentRequestLabel,
        'reference_id' => 'Suborder-' . $suborderId,
        'customer_email' => $customerEmail,
        'customer_name' => $customerName,
        'source' => 'order_access_suborder_full',
        'order_id' => $parentOrder->id,
        'suborder_id' => $suborderId,
        'payment_type' => 'suborder_full',
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

    $paymentRepo = new \App\Repositories\OrdersPaymentsRepository();
    $paymentData = [
        "id_order" => $parentOrder->id,
        "id_suborder" => $suborderId,
        "is_suborder" => 1,
        "amount" => $totalAmount,
        "method" => $activeProvider->provider_type,
        "stripe_charge_id" => $charge->id ?? null,
        "paid_at" => date("Y-m-d H:i:s"),
        "created_at" => date("Y-m-d H:i:s")
    ];
    
    if ($cardBrand) $paymentData["card_brand"] = $cardBrand;
    if ($cardLast4) $paymentData["card_last4"] = $cardLast4;
    if ($cardExpMonth) $paymentData["card_exp_month"] = $cardExpMonth;
    if ($cardExpYear) $paymentData["card_exp_year"] = $cardExpYear;
    
    $paymentSaved = $paymentRepo->add($paymentData);
    
    if (!$paymentSaved) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => TranslationService::trans('planner_hub.failed_save_payment_record')
        ]);
    }

    // Generar recibo PDF y guardar en document_logs
    try {
        $docRepo = new DocumentsLogsRepository();
        $providerName = $activeProvider->provider_type === 'stripe' ? 'Stripe' : ($activeProvider->provider_type === 'square' ? 'Square' : $activeProvider->provider_type);
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($parentOrder->id, $suborder->id, (float)$totalAmount, $providerName, 'Suborder - Full Payment');
        error_log("Receipt PDF generated successfully: " . $receiptPath);
        
        $docRepo->add([
            "id_order" => $parentOrder->id,
            "id_user" => $parentOrder->id_client,
            "doc_type" => "sub_pay_full",
            "file_path" => $receiptPath,
            "hash" => hash_file("sha256", $receiptPath),
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "extra" => json_encode(["suborder_id" => $suborder->id, "charge_id" => $charge->id ?? null]),
        ]);
        error_log("Receipt saved to document_logs successfully");
    } catch (\Throwable $e) {
        error_log("Failed to generate payment receipt: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }

    // Marcar la sub-orden como pagada completamente
    $suborderRepo->update([
        'status_workflow' => 'INVOICE_PAID'
    ], ['id' => $suborderId]);

    // Actualizar status de la orden padre si es necesario
    if ($parentOrder->status_workflow === 'INVOICE_READY') {
        error_log("Updating parent order status to INVOICE_PAID");
        $orderRepo->update(
            ["status_workflow" => "INVOICE_PAID"], 
            ["id" => $parentOrder->id]             
        );
    }

    try {
        $notificationsRepo = new \App\Repositories\NotificationsRepository();
        $publicSuborderUrl = ($_ENV["APP_URL"] ?? "vnv-venue") . "/order-access/suborder?token=" . $token;

        $notificationsRepo->add([
            "id_user" => $parentOrder->id_owner,
            "mensaje" => TranslationService::trans('planner_hub.full_payment_received_suborder', [
                'suborder_id' => $suborderId,
                'amount' => number_format($totalAmount, 2)
            ]),
            "link" => $publicSuborderUrl,
            "leido" => 0
        ]);

        $clientNotificationMessage = TranslationService::trans('planner_hub.payment_complete_suborder', ['suborder_id' => $suborderId]);
        $notificationsRepo->add([
            "id_user" => $parentOrder->id_client,
            "mensaje" => $clientNotificationMessage,
            "link" => $publicSuborderUrl,
            "leido" => 0
        ]);

        $userRepo = new \App\Repositories\UserRepository();
        // Usar getOneWithoutOwnership para evitar filtros de ownership
        $client = $userRepo->getOneWithoutOwnership(["id" => $parentOrder->id_client]);
        
        if ($client && $client->email) {
            // Obtener el idioma del sistema del owner para el correo
            $owner = $userRepo->getOneWithoutOwnership(["id" => $parentOrder->id_owner]);
            // Acceder directamente a la propiedad system_language del objeto de BD
            $systemLanguage = ($owner && isset($owner->system_language) && !empty($owner->system_language)) ? $owner->system_language : 'en';
            
            // Establecer el locale para el correo según el system_language del owner
            TranslationService::setLocale($systemLanguage);
            
            // Usar el id_owner de la orden para las credenciales SMTP del owner (panel/planner-hub/settings/smtp)
            $emailService = new \App\Services\EmailService($parentOrder->id_owner);
            $subject = TranslationService::trans('planner_hub.payment_complete_suborder_email', ['suborder_id' => $suborderId]);
            
            $templateData = [
                'orderId' => $parentOrder->id,
                'subOrderId' => $suborderId,
                'paymentType' => TranslationService::trans('planner_hub.full_payment'),
                'amount' => $totalAmount,
                'eventDate' => date("F j, Y", strtotime($parentOrder->event_date)),
                'eventTime' => date("g:i A", strtotime($parentOrder->start_time)) . ' ' . TranslationService::trans('planner_hub.to') . ' ' . date("g:i A", strtotime($parentOrder->end_time)),
                'location' => $parentOrder->address,
                'orderUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/order-access?token=" . urlencode($token),
                'remainingMessage' => TranslationService::trans('planner_hub.suborder_fully_paid_confirmed'),
                'locale' => $systemLanguage
            ];
            
            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/payment_confirmation.php");
            
            $emailService->sendTemplateEmail(
                $client->email,
                $subject,
                $templatePath,
                $templateData
            );
        }
    } catch (\Exception $e) {
    }

    error_log("Redirecting to success page...");
    // Redirigir a la página de éxito
    LocationUtils::redirectInternal("/order-access/suborder/success/?token=" . urlencode($token));
    
    error_log("=== SUBORDER PAYMENT DEBUG END ===");
});

$router->run();
