<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\StripeAccountsRepository;
use App\Repositories\Connection;
use App\Services\TranslationService;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Response;
use App\Utils\JsonResponse;
use App\Services\StripeServiceV2;
use App\Services\PaymentCardExtractor;
use App\Repositories\DocumentsLogsRepository;
use App\Services\PaymentReceiptPdfGenerator;
use App\Services\NotificationService;
use App\Utils\ProcessingModal;

$router = new \App\Utils\Router();

$router->get(function () {
    $token = $_GET["token"] ?? null;
    if (!$token) LocationUtils::redirectInternal("/404");

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

    $subRepo = new OrdersSuborderRepository();
    $suborder = $subRepo->getByIdWithoutOwnershipCheck(intval($decoded["suborder_id"]));
    if (!$suborder) LocationUtils::redirectInternal("/404");

    $orderRepo = new OrdersRepository();
    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    if (!$parentOrder) LocationUtils::redirectInternal("/404");

    $accountRepo = new StripeAccountsRepository();
    $account = $accountRepo->getByUser($parentOrder->id_owner);
    if (!$account || empty($account->stripe_account_id)) {
        LocationUtils::redirectInternal("/404");
    }

    $db = new Connection();
    $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
    $db->bind(":id", $suborder->id);
    $db->execute();
    $row = $db->fetchAll()[0] ?? null;
    $sumAdvances = (float)($row->total_advanced ?? 0);

    $subtotalCalculated = 0;
    try {
        $servicesRepo = new \App\Repositories\OrderSuborderServicesAssignedRepository();
        $services = $servicesRepo->getServicesWithDetails($suborder->id);
        foreach ($services as $service) {
            $subtotalCalculated += $service->quantity * $service->actual_price;
        }
    } catch (\Throwable $e) {
        error_log("[SUBORDER_ADVANCE][GET] failed to calculate subtotal: " . $e->getMessage());
    }

    $discountValue = (float)($suborder->discount_value ?? 0);
    $base = max($subtotalCalculated - $discountValue, 0);
    $taxRate = (float)($suborder->tax_percertance ?? 0);
    $tax = $base * ($taxRate / 100);
    $totalAmount = round($base + $tax, 2);

    $sumPaid = 0;
    try {
        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) {
        error_log("[SUBORDER_ADVANCE][GET] sum paid failed: " . $e->getMessage());
        $sumPaid = 0;
    }

    $remainingBalance = max($totalAmount - $sumAdvances - $sumPaid, 0);
    $stripeCurrency = strtolower($_ENV["STRIPE_CURRENCY"] ?? 'usd');
    $stripeCountry = strtoupper($_ENV["STRIPE_COUNTRY"] ?? 'US');
    $paymentRequestLabel = TranslationService::trans('planner_hub.suborder_advance_payment', ['suborder_id' => $suborder->id]);
    $suggestedAdvanceCents = (int) round($remainingBalance * 100);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "token" => $token,
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "stripe_key" => $_ENV["STRIPE_PUBLIC"],
        "sum_advances" => $sumAdvances,
        "base_url" => $_ENV["APP_URL"],
        "stripe_currency" => $stripeCurrency,
        "stripe_country" => $stripeCountry,
        "payment_request_label" => $paymentRequestLabel,
        "suggested_advance_cents" => $suggestedAdvanceCents,
        "remaining_balance" => $remainingBalance,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => TranslationService::trans('planner_hub.processing_advance'),
            "message" => TranslationService::trans('planner_hub.registering_payment')
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

$router->post(function () {
    try {
        $token = $_POST["token"] ?? null;
        $decoded = json_decode(base64_decode($token), true);
        if (!$decoded || !isset($decoded["suborder_id"])) return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.invalid_token')]);

        $suborderId = intval($decoded["suborder_id"]);
        $subRepo = new OrdersSuborderRepository();
        $suborder = $subRepo->getByIdWithoutOwnershipCheck($suborderId);
        if (!$suborder) {
            return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.suborder_not_found')]);
        }
        $orderRepo = new OrdersRepository();
        $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
        if ($parentOrder) {
            $parentOrder = (object)$parentOrder;
        }
        if (!$parentOrder) {
            return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.parent_order_not_found')]);
        }

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

        $amountInput = (float)($_POST["advance_amount"] ?? 0);
        $cardToken = $_POST["customer_token"] ?? null;
        $customerEmail = strtolower(trim($_POST["customer_email"] ?? ""));
        if ($amountInput <= 0 || !$cardToken || !$customerEmail) {
            return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.missing_invalid_data')]);
        }

        $accountRepo = new StripeAccountsRepository();
        $account = $accountRepo->getByUser($parentOrder->id_owner);
        if (!$account || empty($account->stripe_account_id)) {
            return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.owner_can_receive_payments')]);
        }

        $db = new Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);

        $suborderServicesRepo = new \App\Repositories\OrderSuborderServicesAssignedRepository();
        $services = $suborderServicesRepo->getServicesWithDetails($suborderId);
        $subtotalCalculated = 0;
        foreach ($services as $s) { $subtotalCalculated += $s->quantity * $s->actual_price; }
        $discountType = $suborder->discount_type ?? 'amount';
        $discountValue = (float)($suborder->discount_value ?? 0);
        // El discount_value ya es el monto real calculado, no necesitamos recalcular
        $discount = $discountValue;
        $discount = max(0, $discount);
        $base = max($subtotalCalculated - $discount, 0);
        $taxRate = (float)($suborder->tax_percertance ?? 0);
        $tax = $base * ($taxRate / 100);
        $total = round($base + $tax, 2);

        $remainingBefore = max($total - $sumAdvances, 0);
        
        // Validate that amount does not exceed total debt
        if ($amountInput > $remainingBefore) {
            error_log("[SUBORDER_ADVANCE][POST] Amount exceeds remaining balance: {$amountInput} > {$remainingBefore}");
            return JsonResponse::createResponse([
                "success" => false,
                "error" => TranslationService::trans('planner_hub.amount_cannot_exceed_remaining', ['amount' => number_format($remainingBefore, 2)])
            ]);
        }
        
        $chargeAmount = $amountInput;

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
                return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.failed_create_customer')]);
            }
        }

        $charge = $stripeService->chargeCustomerOnConnectedAccount(
            $customer->id,
            $chargeAmount,
            $account->stripe_account_id,
            $cardToken
        );
        if (!$charge) {
            return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.failed_create_charge')]);
        }
        
        // Verificar si el pago falló
        if (isset($charge->status) && $charge->status === 'payment_failed') {
            $errorMessage = TranslationService::trans('planner_hub.payment_failed');
            if (isset($charge->_error_details['message'])) {
                $errorMessage = $charge->_error_details['message'];
            }
            return JsonResponse::createResponse(["success" => false, "error" => $errorMessage]);
        }

        $cardDetails = PaymentCardExtractor::extractCardDetails($charge, $stripeService, $account->stripe_account_id);
        $cardBrand = $cardDetails['brand'];
        $cardLast4 = $cardDetails['last4'];
        $cardExpMonth = $cardDetails['exp_month'];
        $cardExpYear = $cardDetails['exp_year'];

        try {
            $paymentRepo = new OrdersPaymentsRepository();
            $paymentData = [
                "id_order" => $parentOrder->id,
                "id_suborder" => $suborderId,
                "amount" => $chargeAmount,
                "method" => "stripe",
                "stripe_charge_id" => $charge->id, // Mantener el nombre del campo por compatibilidad
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
            $db->query("INSERT INTO orders_advances (id_order, is_suborder, id_suborder, amount, total_before, total_after, note, created_at, stripe_charge_id) VALUES (NULL, 1, :sub_id, :amount, :before, :after, :note, NOW(), :charge)");
            $db->bind(":sub_id", $suborder->id);
            $db->bind(":amount", $chargeAmount);
            $db->bind(":before", $remainingBefore);
            $db->bind(":after", max($remainingBefore - $chargeAmount, 0));
            $db->bind(":note", substr((string)($_POST["note"] ?? ''), 0, 255));
            $db->bind(":charge", $charge->id ?? null);
            $db->execute();
        } catch (\Throwable $e) {
            // Fallback si la columna stripe_charge_id no existe en el esquema
            try {
                $db->query("INSERT INTO orders_advances (id_order, is_suborder, id_suborder, amount, total_before, total_after, note, created_at) VALUES (NULL, 1, :sub_id, :amount, :before, :after, :note, NOW())");
                $db->bind(":sub_id", $suborder->id);
                $db->bind(":amount", $chargeAmount);
                $db->bind(":before", $remainingBefore);
                $db->bind(":after", max($remainingBefore - $chargeAmount, 0));
                $db->bind(":note", substr((string)($_POST["note"] ?? ''), 0, 255));
                $db->execute();
            } catch (\Throwable $e2) {
                error_log("[SUBORDER_ADVANCE][POST] Error inserting advance: " . $e2->getMessage());
                return JsonResponse::createResponse(["success" => false, "error" => TranslationService::trans('planner_hub.failed_save_advance_record')]);
            }
        }

        try {
            $docRepo = new DocumentsLogsRepository();
            $receiptPath = PaymentReceiptPdfGenerator::generateAndSave($parentOrder->id, $suborderId, (float)$chargeAmount, 'Stripe', 'Suborder Advance Payment');
            $docRepo->add([
                "id_order" => $parentOrder->id,
                "id_user" => $parentOrder->id_client,
                "doc_type" => "sub_advance_payment",
                "file_path" => $receiptPath,
                "hash" => hash_file("sha256", $receiptPath),
                "ip" => $_SERVER["REMOTE_ADDR"] ?? null,
                "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                "extra" => json_encode(["suborder_id" => $suborderId, "charge_id" => $charge->id ?? null]),
            ]);
        } catch (\Throwable $e) {}

        try {
            $sumAdvancesAfter = $sumAdvances + $chargeAmount;
            
            $db = new Connection();
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_suborder = :id AND is_suborder = 1");
            $db->bind(":id", $suborder->id);
            $db->execute();
            $row = $db->fetchAll()[0] ?? null;
            $sumPaid = (float)($row->total_paid ?? 0);
            
            $totalPaid = $sumAdvancesAfter + $sumPaid;
            
            $firstPercent = $suborder->payment_split_percent_1 ?? 50;
            $firstAmount = round($total * $firstPercent / 100, 2);
            $secondAmount = round($total - $firstAmount, 2);
            
            $statusRepo = new \App\Repositories\OrdersStatusHistoryRepository();
            $suborderRepo = new OrdersSuborderRepository();
            
            $suborderUpdated = $suborderRepo->getByIdWithoutOwnershipCheck($suborderId);
            $currentStatus = $suborderUpdated->status_workflow ?? 'INVOICE_READY';
            
            if ($totalPaid >= $total - 0.01) {
                if ($currentStatus !== 'INVOICE_PAID') {
                    $updateResult = $suborderRepo->update([
                        'status_workflow' => 'INVOICE_PAID'
                    ], ['id' => $suborderId]);
                    
                    if ($updateResult) {
                        try {
                            $dbStatus = new Connection();
                            $dbStatus->query("INSERT INTO orders_status_history (id_order, id_suborder, status, action_type, note, created_by, created_at) VALUES (:id_order, :id_suborder, :status, :action_type, :note, :created_by, :created_at)");
                            $dbStatus->bind(":id_order", $parentOrder->id);
                            $dbStatus->bind(":id_suborder", $suborderId);
                            $dbStatus->bind(":status", "INVOICE_PAID");
                            $dbStatus->bind(":action_type", "suborder_advance_complete");
                            $dbStatus->bind(":note", TranslationService::trans('planner_hub.suborder_fully_paid_advance', ['amount' => number_format($chargeAmount, 2)]));
                            $dbStatus->bind(":created_by", 0);
                            $dbStatus->bind(":created_at", date("Y-m-d H:i:s"));
                            $dbStatus->execute();
                        } catch (\Throwable $e) {
                            error_log("[SUBORDER_ADVANCE][POST] Error adding status history: " . $e->getMessage());
                        }
                    }
                }
            } elseif ($totalPaid >= $firstAmount - 0.01 && $currentStatus !== 'INVOICE_PAID') {
                if ($currentStatus !== 'INVOICE_PARTIAL') {
                    $updateResult = $suborderRepo->update([
                        'status_workflow' => 'INVOICE_PARTIAL'
                    ], ['id' => $suborderId]);
                    
                    if ($updateResult) {
                        try {
                            $dbStatus = new Connection();
                            $dbStatus->query("INSERT INTO orders_status_history (id_order, id_suborder, status, action_type, note, created_by, created_at) VALUES (:id_order, :id_suborder, :status, :action_type, :note, :created_by, :created_at)");
                            $dbStatus->bind(":id_order", $parentOrder->id);
                            $dbStatus->bind(":id_suborder", $suborderId);
                            $dbStatus->bind(":status", "INVOICE_PARTIAL");
                            $dbStatus->bind(":action_type", "suborder_advance_first");
                            $dbStatus->bind(":note", TranslationService::trans('planner_hub.first_payment_completed_advance', ['amount' => number_format($chargeAmount, 2)]));
                            $dbStatus->bind(":created_by", 0);
                            $dbStatus->bind(":created_at", date("Y-m-d H:i:s"));
                            $dbStatus->execute();
                        } catch (\Throwable $e) {
                            error_log("[SUBORDER_ADVANCE][POST] Error adding status history: " . $e->getMessage());
                        }
                    }
                }
            } elseif ($totalPaid >= $firstAmount + $secondAmount - 0.01 && $currentStatus !== 'INVOICE_PAID') {
                $updateResult = $suborderRepo->update([
                    'status_workflow' => 'INVOICE_PAID'
                ], ['id' => $suborderId]);
                
                if ($updateResult) {
                    try {
                        $dbStatus = new Connection();
                        $dbStatus->query("INSERT INTO orders_status_history (id_order, id_suborder, status, action_type, note, created_by, created_at) VALUES (:id_order, :id_suborder, :status, :action_type, :note, :created_by, :created_at)");
                        $dbStatus->bind(":id_order", $parentOrder->id);
                        $dbStatus->bind(":id_suborder", $suborderId);
                        $dbStatus->bind(":status", "INVOICE_PAID");
                        $dbStatus->bind(":action_type", "suborder_advance_second");
                        $dbStatus->bind(":note", TranslationService::trans('planner_hub.second_payment_completed_advance', ['amount' => number_format($chargeAmount, 2)]));
                        $dbStatus->bind(":created_by", 0);
                        $dbStatus->bind(":created_at", date("Y-m-d H:i:s"));
                        $dbStatus->execute();
                    } catch (\Throwable $e) {
                        error_log("[SUBORDER_ADVANCE][POST] Error adding status history: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[SUBORDER_ADVANCE][POST] Error updating status: " . $e->getMessage());
            error_log("[SUBORDER_ADVANCE][POST] Stack trace: " . $e->getTraceAsString());
        }

        try {
            NotificationService::sendToUsers(
                [$parentOrder->id_owner],
                '💵 Advance Received',
                'An advance of $' . number_format($chargeAmount, 2) . ' has been received for suborder #' . $suborderId . ' of order # VNV341' . $parentOrder->id
            );
        } catch (\Throwable $e) {
            error_log("[SUBORDER_ADVANCE][POST] Error sending notification: " . $e->getMessage());
        }

        return JsonResponse::createResponse([
            "success" => true,
            "redirect" => $_ENV["APP_URL"] . "/order-access/suborder?token=" . urlencode($token)
        ]);
    } catch (\Throwable $e) {
        error_log("[SUBORDER_ADVANCE][POST] Unexpected error: " . $e->getMessage());
        error_log("[SUBORDER_ADVANCE][POST] Stack trace: " . $e->getTraceAsString());
        return JsonResponse::createResponse([
            "success" => false,
            "error" => TranslationService::trans('planner_hub.error_occurred_processing_advance')
        ]);
    }
});

$router->run();


