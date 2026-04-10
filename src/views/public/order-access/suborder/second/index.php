<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\SquareAccountsRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Services\SquareServiceV2;
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

    if ($hashCheck !== $decoded["hash"] || time() > $decoded["exp"]) {
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
    $total = $base + $tax;

    // Restar abonos previos de la suborden y el primer pago si existe
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) { $sumAdvances = 0; }

    // Restar primer pago (si ya se realizó)
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) { $sumPaid = 0; }

    $remaining = max($total - $sumAdvances - $sumPaid, 0);
    $secondPercent = $suborder->payment_split_percent_2 ?? 50;
    // En la segunda pantalla, el monto a cobrar debe ser el restante
    $secondAmount = round($remaining, 2);

    $paymentRequestLabel = sprintf("Suborder #%s - Final Payment", $suborder->id);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "second_payment_amount" => $secondAmount,
        "square_application_id" => $_ENV["SQUARE_APPLICATION_ID"] ?? "",
        "square_location_id" => $_ENV["SQUARE_LOCATION_ID"] ?? "",
        "square_environment" => $_ENV["SQUARE_ENVIRONMENT"] ?? "sandbox",
        "payment_request_label" => $paymentRequestLabel,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => "Processing payment...",
            "message" => "We are confirming your payment. Please wait."
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

$router->post(function () {
    $squareService = new SquareServiceV2();
    $token = $_POST["token"] ?? null;
    $decoded = json_decode(base64_decode($token), true);
    if (!$decoded || !isset($decoded["suborder_id"])) return Response::createResponse("Invalid token");

    $suborderId = intval($decoded["suborder_id"]);
    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();
    $suborder = $suborderRepo->getByIdWithoutOwnershipCheck($suborderId);
    if (!$suborder) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Suborder not found"
        ]);
    }

    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    if (!$parentOrder) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Parent order not found"
        ]);
    }

    $accountRepo = new SquareAccountsRepository();
    $account = $accountRepo->getByUser($parentOrder->id_owner);
    if (!$account || empty($account->square_account_id)) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Owner cannot receive payments"
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
    $total = $base + $tax;

    // Restar abonos previos y el primer pago
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborderId);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) { $sumAdvances = 0; }

    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborderId);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) { $sumPaid = 0; }

    $remaining = max($total - $sumAdvances - $sumPaid, 0);
    $secondAmount = round($remaining, 2);

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

    // Procesar pago con Square como en el flujo de órdenes (token -> customer -> charge)
    $cardToken = $_POST["customer_token"] ?? null;
    $customerEmail = strtolower(trim($_POST["customer_email"] ?? ""));

    if (!$cardToken || !$customerEmail) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Missing payment data"
        ]);
    }

    $customer = $squareService->getCustomerOnConnectedAccount($customerEmail, $account->square_account_id);

    if (!$customer) {
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
        $secondAmount,
        $account->square_account_id,
        $cardToken
    );

    if (!$charge) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Failed to create charge"
        ]);
    }
    
    // Verificar si el pago falló
    if (isset($charge->status) && $charge->status === 'payment_failed') {
        $errorMessage = "Payment failed";
        if (isset($charge->_error_details['message'])) {
            $errorMessage = $charge->_error_details['message'];
        }
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => $errorMessage
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
            $cardBrand = $details->card->brand ?? null;
            $cardLast4 = $details->card->last4 ?? null;
            $cardExpMonth = $details->card->exp_month ?? null;
            $cardExpYear = $details->card->exp_year ?? null;
        }
    }

    $paymentRepo = new \App\Repositories\OrdersPaymentsRepository();
    $paymentData = [
        "id_order" => $parentOrder->id,
        "id_suborder" => $suborderId,
        "is_suborder" => 1,
        "amount" => $secondAmount,
        "method" => "square",
        "stripe_charge_id" => $charge->id, // Mantener el nombre del campo por compatibilidad
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
            "error" => "Failed to save payment record"
        ]);
    }

    // Generar recibo PDF y guardar en document_logs
    try {
        $docRepo = new DocumentsLogsRepository();
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($parentOrder->id, $suborderId, (float)$secondAmount, 'Square', 'Suborder - Final Installment');
        error_log("Receipt PDF generated successfully: " . $receiptPath);
        
        $docRepo->add([
            "id_order" => $parentOrder->id,
            "id_user" => $parentOrder->id_client,
            "doc_type" => "sub_pay_second",
            "file_path" => $receiptPath,
            "hash" => hash_file("sha256", $receiptPath),
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "extra" => json_encode(["suborder_id" => $suborderId, "charge_id" => $charge->id ?? null]),
        ]);
        error_log("Receipt saved to document_logs successfully");
    } catch (\Throwable $e) {
        error_log("Failed to generate payment receipt: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }

    // Marcar suborden como pagada completamente
    $suborderRepo->update([
        'status_workflow' => 'INVOICE_PAID'
    ], ['id' => $suborderId]);

    if ($parentOrder->status_workflow === 'INVOICE_PARTIAL') {
        $orderRepo->update([
            'status_workflow' => 'INVOICE_PAID'
        ], ['id' => $parentOrder->id]);
    }

    try {
        $notificationsRepo = new \App\Repositories\NotificationsRepository();
        $notificationsRepo->add([
            "id_user" => $parentOrder->id_owner,
            "mensaje" => "💳 Second Payment Received - Suborder #{$suborderId} second payment of $" . number_format($secondAmount, 2) . " has been received. Suborder is now fully paid.",
            "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/management/orders/orders",
            "leido" => 0
        ]);

        $clientNotificationMessage = "🎉 Payment Complete - All payments for sub-order #{$suborderId} have been received successfully.";
        $notificationsRepo->add([
            "id_user" => $parentOrder->id_client,
            "mensaje" => $clientNotificationMessage,
            "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/orders/orders",
            "leido" => 0
        ]);

        $userRepo = new \App\Repositories\UserRepository();
        $client = $userRepo->getOne(["id" => $parentOrder->id_client]);
        
        if ($client && $client->email) {
            $emailService = new \App\Services\EmailService();
            $subject = "VNV-Events - 🎉 Payment Complete - Sub-Order #{$suborderId}";
            
            $templateData = [
                'orderId' => $parentOrder->id,
                'subOrderId' => $suborderId,
                'paymentType' => 'Final Payment',
                'amount' => $secondAmount,
                'eventDate' => date("F j, Y", strtotime($parentOrder->event_date)),
                'eventTime' => date("g:i A", strtotime($parentOrder->start_time)) . ' to ' . date("g:i A", strtotime($parentOrder->end_time)),
                'location' => $parentOrder->address,
                'orderUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/order-access?token=" . urlencode($token),
                'remainingMessage' => 'Your sub-order is now fully paid and confirmed!'
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

    LocationUtils::redirectInternal("/order-access/suborder/success/?token=" . urlencode($token));
});

$router->run();
