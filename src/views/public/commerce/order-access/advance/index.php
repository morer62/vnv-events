<?php

use App\Repositories\OrdersRepository;
use App\Repositories\StripeAccountsRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Response;
use App\Repositories\Connection;
use App\Services\StripeServiceV2;
use App\Services\PaymentCardExtractor;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Services\NotificationService;
use App\Services\TranslationService;
use App\Utils\ProcessingModal;

$router = new \App\Utils\Router();

$router->get(function () {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
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

    $accountRepo = new StripeAccountsRepository();
    $account = $accountRepo->getByUser($order->id_owner);
    if (!$account || empty($account->stripe_account_id)) {
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Owner cannot receive payments"
        ]);
    }

    $sumAdvances = 0;
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        error_log("[ADVANCE][GET] sum advances failed: " . $e->getMessage());
        $sumAdvances = 0;
    }

    $subtotalCalculated = 0;
    try {
        $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
        $serviceRepo = new \App\Repositories\OrdersServiceRepository();
        $assigned = $assignedRepo->getAllWithoutOwner(["id_order" => $order->id]);

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
    } catch (\Throwable $e) {
        error_log("[ADVANCE][GET] failed to calculate subtotal: " . $e->getMessage());
    }

    $discountValue = $order->discount_value ?? 0;
    $base = max($subtotalCalculated - $discountValue, 0);
    $taxRate = $order->tax_percentage ?? 0;
    $tax = $base * ($taxRate / 100);
    $totalAmount = round($base + $tax, 2);

    $sumPaid = 0;
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) {
        error_log("[ADVANCE][GET] sum paid failed: " . $e->getMessage());
        $sumPaid = 0;
    }

    $remainingBalance = max($totalAmount - $sumAdvances - $sumPaid, 0);
    $stripeCurrency = strtolower($_ENV["STRIPE_CURRENCY"] ?? 'usd');
    $stripeCountry = strtoupper($_ENV["STRIPE_COUNTRY"] ?? 'US');
    $paymentRequestLabel = TranslationService::trans('planner_hub.order_advance_payment', ['order_id' => $order->id]);
    $suggestedAdvanceCents = (int) round($remainingBalance * 100);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "order" => $order,
        "stripe_key" => $_ENV["STRIPE_PUBLIC"],
        "sum_advances" => $sumAdvances,
        "remaining_balance" => $remainingBalance,
        "base_url" => $_ENV["APP_URL"],
        "stripe_currency" => $stripeCurrency,
        "stripe_country" => $stripeCountry,
        "payment_request_label" => $paymentRequestLabel,
        "suggested_advance_cents" => $suggestedAdvanceCents,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => TranslationService::trans('planner_hub.processing_advance'),
            "message" => TranslationService::trans('planner_hub.processing_advance')
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

$router->post(function () {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    error_log("[ADVANCE][POST] Start processing advance payment");
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
        error_log("[ADVANCE][POST] Order not found");
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Order not found"
        ]);
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

    $amountInput = (float)($_POST["advance_amount"] ?? 0);
    $cardToken = $_POST["customer_token"] ?? null;
    $customerEmail = strtolower(trim($_POST["customer_email"] ?? ""));
    if ($amountInput <= 0 || !$cardToken || !$customerEmail) {
        error_log("[ADVANCE][POST] Missing or invalid data amount={$amountInput} tokenPresent=" . (bool)$cardToken . " email={$customerEmail}");
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Missing or invalid data"
        ]);
    }

    $accountRepo = new StripeAccountsRepository();
    $account = $accountRepo->getByUser($order->id_owner);
    if (!$account || empty($account->stripe_account_id)) {
        error_log("[ADVANCE][POST] Owner cannot receive payments");
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Owner can receive payments"
        ]);
    }

    $db = new Connection();
    $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
    $db->bind(":id", $order->id);
    $db->execute();
    $row = $db->fetchAll()[0] ?? null;
    $sumAdvances = (float)($row->total_advanced ?? 0);

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
            $unitPrice = ($a->is_variable === 'YES' && $a->variable_price !== null) ? $a->variable_price : $service->price;
        }
        $subtotalCalculated += $a->quantity * $unitPrice;
    }
    $discountType = $order->discount_type ?? 'amount';
    $discountValue = $order->discount_value ?? 0;
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $descuento = $discountValue;
    $base = max($subtotalCalculated - $descuento, 0);
    $taxRate = $order->tax_percentage ?? 0;
    $tax = $base * ($taxRate / 100);
    $total = round($base + $tax, 2);

    // Si la orden es split, usar el saldo restante como base de avance (igual funciona para one payment)
    // Subtract previous advances
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

    $remainingBefore = max($total - $sumAdvances - $sumPaid, 0);
    
    // Validate that amount does not exceed total debt
    if ($amountInput > $remainingBefore) {
        error_log("[ADVANCE][POST] Amount exceeds remaining balance: {$amountInput} > {$remainingBefore}");
        return Response::createResponse(json_encode([
            "success" => false,
            "error" => "Amount cannot exceed remaining balance of $" . number_format($remainingBefore, 2)
        ]));
    }
    
    $chargeAmount = $amountInput;
    error_log("[ADVANCE][POST] Calculated remainingBefore={$remainingBefore} chargeAmount={$chargeAmount}");

    $stripeService = new StripeServiceV2();
    $customer = $stripeService->getCustomerOnConnectedAccount($customerEmail, $account->stripe_account_id);
    if (!$customer) {
        $customer = $stripeService->createCustomerWithCardOnConnectedAccount(
            $cardToken,
            $customerEmail,
            $customerName,
            $account->stripe_account_id
        );
        
        if (!$customer) {
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Failed to create customer"
            ]);
        }
    }
    // Si el customer ya existe, usarlo directamente sin actualizar la fuente
    // Los tokens de Stripe solo se pueden usar una vez

    $charge = $stripeService->chargeCustomerOnConnectedAccount(
        $customer->id,
        $chargeAmount,
        $account->stripe_account_id,
        $cardToken // Pasar el token nuevo para usar la tarjeta ingresada
    );
    if (!$charge) {
        error_log("[ADVANCE][POST] Stripe charge failed");
        return TemplateResponse::render(__DIR__ . "/error.twig", [
            "error" => "Failed to create charge"
        ]);
    }

    $cardDetails = PaymentCardExtractor::extractCardDetails($charge, $stripeService, $account->stripe_account_id);
    $cardBrand = $cardDetails['brand'];
    $cardLast4 = $cardDetails['last4'];
    $cardExpMonth = $cardDetails['exp_month'];
    $cardExpYear = $cardDetails['exp_year'];

    try {
        $paymentRepo = new OrdersPaymentsRepository();
        $paymentData = [
            "id_order" => $order->id,
            "amount" => $chargeAmount,
            "method" => "stripe",
            "stripe_charge_id" => $charge->id,
            "paid_at" => date("Y-m-d H:i:s"),
            "created_at" => date("Y-m-d H:i:s")
        ];
        
        if ($cardBrand) {
            $paymentData["card_brand"] = $cardBrand;
        }
        if ($cardLast4) {
            $paymentData["card_last4"] = $cardLast4;
        }
        if ($cardExpMonth) {
            $paymentData["card_exp_month"] = $cardExpMonth;
        }
        if ($cardExpYear) {
            $paymentData["card_exp_year"] = $cardExpYear;
        }
        
        $paymentRepo->add($paymentData);
    } catch (\Exception $e) {
    }

    try {
        $db->query("INSERT INTO orders_advances (id_order, is_suborder, id_suborder, amount, total_before, total_after, note, created_at, stripe_charge_id) VALUES (:order_id, 0, NULL, :amount, :before, :after, :note, NOW(), :charge)");
        $db->bind(":order_id", $order->id);
        $db->bind(":amount", $chargeAmount);
        $db->bind(":before", $remainingBefore);
        $db->bind(":after", max($remainingBefore - $chargeAmount, 0));
        $db->bind(":note", substr((string)($_POST["note"] ?? ''), 0, 255));
        $db->bind(":charge", $charge->id ?? null);
        $db->execute();
    } catch (\Throwable $e) {
        error_log("[ADVANCE][POST] insert with charge_id failed: " . $e->getMessage());
        // Fallback sin la columna stripe_charge_id (por compatibilidad de esquema)
        try {
            $db->query("INSERT INTO orders_advances (id_order, is_suborder, id_suborder, amount, total_before, total_after, note, created_at) VALUES (:order_id, 0, NULL, :amount, :before, :after, :note, NOW())");
            $db->bind(":order_id", $order->id);
            $db->bind(":amount", $chargeAmount);
            $db->bind(":before", $remainingBefore);
            $db->bind(":after", max($remainingBefore - $chargeAmount, 0));
            $db->bind(":note", substr((string)($_POST["note"] ?? ''), 0, 255));
            $db->execute();
        } catch (\Throwable $e2) {
            error_log("[ADVANCE][POST] insert fallback failed: " . $e2->getMessage());
            return TemplateResponse::render(__DIR__ . "/error.twig", [
                "error" => "Failed to save advance: " . $e2->getMessage()
            ]);
        }
    }
    error_log("[ADVANCE][POST] Saved advance in DB");

    // Check if advance covers the full remaining balance and update order status
    $remainingAfterAdvance = max($remainingBefore - $chargeAmount, 0);
    error_log("[ADVANCE][POST] Remaining after advance: {$remainingAfterAdvance}");
    
    if ($remainingAfterAdvance <= 0) {
        // Advance covers full remaining balance - mark as fully paid
        $orderRepo->update([
            "status_workflow" => "INVOICE_PAID"
        ], ["id" => $order->id]);
        
        // Add status history
        $statusRepo = new \App\Repositories\OrdersStatusHistoryRepository();
        $statusRepo->add([
            "id_order" => $order->id,
            "status" => "INVOICE_PAID",
            "action_type" => "advance_payment_complete",
            "note" => TranslationService::trans('planner_hub.order_fully_paid_advance', ['amount' => number_format($chargeAmount, 2)]),
            "created_by" => 0,
            "created_at" => date("Y-m-d H:i:s")
        ]);
        
        error_log("[ADVANCE][POST] Order marked as fully paid due to advance");
    }

    try {
        $docRepo = new DocumentsLogsRepository();
        $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($order->id, null, (float)$chargeAmount, 'Stripe', TranslationService::trans('planner_hub.add_advance'));
        $docRepo->add([
            "id_order" => $order->id,
            "id_user" => $order->id_client,
            "doc_type" => "advance_payment",
            "file_path" => $receiptPath,
            "hash" => hash_file("sha256", $receiptPath),
            "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
            "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "extra" => json_encode(["order_id" => $order->id, "charge_id" => $charge->id ?? null]),
        ]);
    } catch (\Throwable $e) {}

    // Send notification
    $notificationMessage = TranslationService::trans('planner_hub.advance_received_notification', [
        'amount' => number_format($chargeAmount, 2),
        'order_id' => $order->id
    ]);
    if ($remainingAfterAdvance <= 0) {
        $notificationMessage .= ' - ' . TranslationService::trans('planner_hub.order_now_fully_paid');
    }
    
    NotificationService::sendToUsers(
        [$order->id_owner],
        '💵 ' . TranslationService::trans('planner_hub.advance_received_title'),
        $notificationMessage
    );
    error_log("[ADVANCE][POST] Advance processed successfully");
    
    return Response::createResponse(json_encode([
        "success" => true,
        "redirect" => $_ENV["APP_URL"] . "/order-access?token=" . urlencode($token) . "&t=" . time()
    ]));
});

$router->run();
