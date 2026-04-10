<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Services\LoginService;
use App\Services\OrderCalculatorService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $id = $_GET["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders");
    }

    $ordersRepo = new OrdersRepository();
    $historyRepo = new OrdersStatusHistoryRepository();

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $ordersRepo->getOneByIdAndOwner($id, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $ordersRepo->getOne(["id" => $id]);
    }
    
    if (!$order) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }
    
    $history = $historyRepo->getAllBy(["id_order" => $id]);

    // Generar token
    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $payload = [
        "order_id" => $order->id,
        "user_id" => $order->id_client,
        "exp" => time() + (86400 * 30)
    ];
    $payload["hash"] = hash_hmac("sha256", json_encode($payload), $secret);
    $contractToken = base64_encode(json_encode($payload));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "history" => $history,
        "contract_token" => $contractToken
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();

    $orderId = $_POST["id_order"] ?? null;
    $newStatus = $_POST["status"] ?? null;
    $actionType = $_POST["action_type"] ?? "manual_change";
    $note = $_POST["note"] ?? null;

    $ordersRepo = new OrdersRepository();
    $historyRepo = new OrdersStatusHistoryRepository();
    $docRepo = new DocumentsLogsRepository();
    $paymentsRepo = new OrdersPaymentsRepository();

    if (isset($_POST['undo_last_status'])) {
        $id_status = $_POST['undo_last_status'];
        $statusToDelete = $historyRepo->getOne(['id' => $id_status]);

        if (!$statusToDelete) {
            MessageUtil::setMessage("Status not found.");
            LocationUtils::reload();
        }

        $allStatus = $historyRepo->getAllByOrderSorted(['id_order' => $orderId], 'created_at DESC');

        if (count($allStatus) <= 1) {
            MessageUtil::setMessage("Cannot undo the only status.");
            LocationUtils::reload();
        }

        if ($allStatus[0]->id != $statusToDelete->id) {
            MessageUtil::setMessage("Only the most recent status can be undone.");
            LocationUtils::reload();
        }

        try {
            $currentStatusTrimmed = trim($statusToDelete->status);
            if ($currentStatusTrimmed === 'INVOICE_PAID' || $currentStatusTrimmed === 'INVOICE_PARTIAL') {
                $payments = $paymentsRepo->getAllBy(["id_order" => $orderId]);
                if (!empty($payments)) {
                    if ($session->getLevel() === 4) {
                        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
                        if ($currentInstitutionId) {
                            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                            $institution = $institutionRepo->getById($currentInstitutionId);
                            if ($institution && $institution->id_owner) {
                                $currentOrder = $ordersRepo->getOneByIdAndOwner($orderId, $institution->id_owner);
                            } else {
                                $currentOrder = null;
                            }
                        } else {
                            $currentOrder = null;
                        }
                    } else {
                        $currentOrder = $ordersRepo->getOne(["id" => $orderId]);
                    }
                    if ($currentOrder && (int)$currentOrder->payment_split_type === 2) {
                        usort($payments, function($a, $b) {
                            $ap = $a->paid_at ?? $a->created_at ?? null;
                            $bp = $b->paid_at ?? $b->created_at ?? null;
                            return strtotime($bp) <=> strtotime($ap);
                        });
                        $latestPayment = $payments[0];
                        $paymentsRepo->delete(['id' => $latestPayment->id]);
                    } else {
                        foreach ($payments as $payment) {
                            $paymentsRepo->delete(['id' => $payment->id]);
                        }
                    }
                }
            }
            if ($currentStatusTrimmed === 'INVOICE_READY') {
                $existingDoc = $docRepo->getByType((int)$orderId, 'contract_signed');
                if ($existingDoc) {
                    $docRepo->delete(['id' => $existingDoc->id]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Error revirtiendo documentos/pagos en UNDO: " . $e->getMessage());
        }

        $determinePreviousStatus = function($currentStatus, $allStatus) {
            $status = trim($currentStatus);
            if ($status === 'INVOICE_PAID' || $status === 'INVOICE_PARTIAL') {
                foreach ($allStatus as $index => $s) {
                    if ($s->status === $status) {
                        for ($i = $index + 1; $i < count($allStatus); $i++) {
                            $prev = trim($allStatus[$i]->status);
                            if ($prev !== 'INVOICE_PAID' && $prev !== 'INVOICE_PARTIAL') {
                                return $prev;
                            }
                        }
                        return 'INVOICE_READY';
                    }
                }
            }
            return count($allStatus) > 1 ? trim($allStatus[1]->status) : 'INVOICE_DRAFT';
        };

        $previousStatus = $determinePreviousStatus($statusToDelete->status, $allStatus);

        $historyRepo->delete(['id' => $id_status]);
        $ordersRepo->update([
            'status_workflow' => $previousStatus
        ], ['id' => $orderId]);

        MessageUtil::setMessage("Last status removed and order updated.");
        LocationUtils::reload();
    }

    if (!$orderId || !$newStatus) {
        MessageUtil::setMessage("Missing data.");
        LocationUtils::reload();
    }

    $filePath = null;
    if (FileUtils::hasFile($_FILES, 'file')) {
        $filePath = FileUtils::saveFile($_FILES['file'], 'orders/status');
    }

    if ($session->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $ordersRepo->getOneByIdAndOwner($orderId, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $ordersRepo->getOne(["id" => $orderId]);
    }
    
    if (!$order) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::reload();
    }

 if (trim($newStatus) === 'INVOICE_PARTIAL') {
  if ((int)$order->payment_split_type === 1) {
   MessageUtil::setMessage("Partial payment is not allowed: this order is single payment.");
   LocationUtils::reload();
  }
 }

    $historyRepo->add([
        "id_order" => $orderId,
        "status" => $newStatus,
        "action_type" => $actionType,
        "file_path" => $filePath,
        "note" => $note,
        "created_by" => $session->getId(),
    ]);

    $ordersRepo->update([
        "status_workflow" => $newStatus
    ], ["id" => $orderId]);

    $statusesThatSign = ["INVOICE_READY", "INVOICE_PARTIAL", "INVOICE_PAID"];
    if (in_array(trim($newStatus), $statusesThatSign, true)) {

        $alreadySigned = $docRepo->getByType((int) $orderId, "contract_signed");

        if (!$alreadySigned) {
            try {
                // Usar hora local del servidor sin cambiar timezone
                $docFilePath = $filePath ?? "";
                $docHash = "";

                if ($docFilePath && file_exists($docFilePath)) {
                    $docHash = hash_file("sha256", $docFilePath);
                }

                $docRepo->add([
                    "id_order" => $orderId,
                    "id_user" => $session->getId(),
                    "doc_type" => "contract_signed",
                    "file_path" => $docFilePath,
                    "hash" => $docHash,
                    "ip" => $_SERVER["REMOTE_ADDR"] ?? '',
                    "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? '',
                    "extra" => json_encode(["method" => "manual_update"]),
                    "generated_at" => date("Y-m-d H:i:s")
                ]);
            } catch (\Throwable $e) {
                error_log("Error insertando contract_signed: " . $e->getMessage());
            }
        }
    }

    if (trim($newStatus) === "INVOICE_PAID") {
        try {
            $existingPayments = $paymentsRepo->getAllBy(["id_order" => $orderId]);

            if (empty($existingPayments)) {
                // Calcular total manualmente considerando precios variables
                $total = 0;
                $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
                $assignedServices = $assignedRepo->getAllBy(["id_order" => $orderId]);
                
                foreach ($assignedServices as $item) {
                    if ($item->is_variable === 'YES' && !empty($item->variable_price)) {
                        $total += ($item->variable_price * $item->quantity);
                    } else {
                        $total += ($item->subtotal);
                    }
                }
                
                // Aplicar descuento si existe
                if (!empty($order->discount_amount) && $order->discount_amount > 0) {
                    $total -= $order->discount_amount;
                }
                
                // Aplicar impuestos si existen
                if (!empty($order->tax_rate) && $order->tax_rate > 0) {
                    $taxAmount = round($total * ($order->tax_rate / 100), 2);
                    $total += $taxAmount;
                }
                
                // Aplicar tarifa de procesamiento de Stripe (2.90%)
                $stripeFee = round($total * 0.029, 2);
                $total += $stripeFee;

                $paymentsRepo->add([
                    "id_order" => $orderId,
                    "amount" => $total,
                    "method" => "manual_admin",
                    "stripe_charge_id" => null,
                    "paid_at" => date("Y-m-d H:i:s"),
                    "is_refunded" => 0,
                    "created_at" => date("Y-m-d H:i:s")
                ]);
            } else {
                if ((int)$order->payment_split_type === 2 && count($existingPayments) === 1) {
                    // Calcular total manualmente para el segundo pago
                    $total = 0;
                    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
                    $assignedServices = $assignedRepo->getAllBy(["id_order" => $orderId]);
                    
                    foreach ($assignedServices as $item) {
                        if ($item->is_variable === 'YES' && !empty($item->variable_price)) {
                            $total += ($item->variable_price * $item->quantity);
                        } else {
                            $total += ($item->subtotal);
                        }
                    }
                    
                    // Aplicar descuento si existe
                    if (!empty($order->discount_amount) && $order->discount_amount > 0) {
                        $total -= $order->discount_amount;
                    }
                    
                    // Aplicar impuestos si existen
                    if (!empty($order->tax_rate) && $order->tax_rate > 0) {
                        $taxAmount = round($total * ($order->tax_rate / 100), 2);
                        $total += $taxAmount;
                    }
                    
                    // Aplicar tarifa de procesamiento de Stripe (2.90%)
                    $stripeFee = round($total * 0.029, 2);
                    $total += $stripeFee;
                    
                    $secondPercent = $order->payment_split_percent_2 ?? 50;
                    $secondPaymentAmount = round($total * $secondPercent / 100, 2);
                    
                    $paymentsRepo->add([
                        "id_order" => $orderId,
                        "amount" => $secondPaymentAmount,
                        "method" => "manual_admin",
                        "stripe_charge_id" => null,
                        "paid_at" => date("Y-m-d H:i:s"),
                        "is_refunded" => 0,
                        "created_at" => date("Y-m-d H:i:s")
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Error creando pago automático: " . $e->getMessage());
        }
    }

    if (trim($newStatus) === "INVOICE_PARTIAL") {
        try {
            if ((int)$order->payment_split_type === 2) {
                $existingPayments = $paymentsRepo->getAllBy(["id_order" => $orderId]);
                
                if (empty($existingPayments)) {
                    // Calcular total manualmente para el primer pago
                    $total = 0;
                    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
                    $assignedServices = $assignedRepo->getAllBy(["id_order" => $orderId]);
                    
                    foreach ($assignedServices as $item) {
                        if ($item->is_variable === 'YES' && !empty($item->variable_price)) {
                            $total += ($item->variable_price * $item->quantity);
                        } else {
                            $total += ($item->subtotal);
                        }
                    }
                    
                    // Aplicar descuento si existe
                    if (!empty($order->discount_amount) && $order->discount_amount > 0) {
                        $total -= $order->discount_amount;
                    }
                    
                    // Aplicar impuestos si existen
                    if (!empty($order->tax_rate) && $order->tax_rate > 0) {
                        $taxAmount = round($total * ($order->tax_rate / 100), 2);
                        $total += $taxAmount;
                    }
                    
                    // Aplicar tarifa de procesamiento de Stripe (2.90%)
                    $stripeFee = round($total * 0.029, 2);
                    $total += $stripeFee;
                    
                    $firstPercent = $order->payment_split_percent_1 ?? 50;
                    $firstPaymentAmount = round($total * $firstPercent / 100, 2);
                    
                    $paymentsRepo->add([
                        "id_order" => $orderId,
                        "amount" => $firstPaymentAmount,
                        "method" => "manual_admin",
                        "stripe_charge_id" => null,
                        "paid_at" => date("Y-m-d H:i:s"),
                        "is_refunded" => 0,
                        "created_at" => date("Y-m-d H:i:s")
                    ]);
                } else {
                    // Calcular total manualmente para el segundo pago
                    $total = 0;
                    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
                    $assignedServices = $assignedRepo->getAllBy(["id_order" => $orderId]);
                    
                    foreach ($assignedServices as $item) {
                        if ($item->is_variable === 'YES' && !empty($item->variable_price)) {
                            $total += ($item->variable_price * $item->quantity);
                        } else {
                            $total += ($item->subtotal);
                        }
                    }
                    
                    // Aplicar descuento si existe
                    if (!empty($order->discount_amount) && $order->discount_amount > 0) {
                        $total -= $order->discount_amount;
                    }
                    
                    // Aplicar impuestos si existen
                    if (!empty($order->tax_rate) && $order->tax_rate > 0) {
                        $taxAmount = round($total * ($order->tax_rate / 100), 2);
                        $total += $taxAmount;
                    }
                    
                    // Aplicar tarifa de procesamiento de Stripe (2.90%)
                    $stripeFee = round($total * 0.029, 2);
                    $total += $stripeFee;
                    
                    $secondPercent = $order->payment_split_percent_2 ?? 50;
                    $secondPaymentAmount = round($total * $secondPercent / 100, 2);
                    
                    $paymentsRepo->add([
                        "id_order" => $orderId,
                        "amount" => $secondPaymentAmount,
                        "method" => "manual_admin",
                        "stripe_charge_id" => null,
                        "paid_at" => date("Y-m-d H:i:s"),
                        "is_refunded" => 0,
                        "created_at" => date("Y-m-d H:i:s")
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Error creando pago dividido: " . $e->getMessage());
        }
    }

    MessageUtil::setMessage("Status updated successfully.");
    LocationUtils::reload();
});

function determinePreviousStatus($currentStatus, $allStatus) {
    $status = trim($currentStatus);
    
    if ($status === "INVOICE_PAID") {
        foreach ($allStatus as $index => $statusRecord) {
            if ($statusRecord->status === "INVOICE_PAID") {
                for ($i = $index + 1; $i < count($allStatus); $i++) {
                    $previousStatus = trim($allStatus[$i]->status);
                    if (!in_array($previousStatus, ["INVOICE_PAID", "INVOICE_PARTIAL"])) {
                        return $previousStatus;
                    }
                }
                return "INVOICE_READY";
            }
        }
    }
    
    if ($status === "INVOICE_PARTIAL") {
        foreach ($allStatus as $index => $statusRecord) {
            if ($statusRecord->status === "INVOICE_PARTIAL") {
                for ($i = $index + 1; $i < count($allStatus); $i++) {
                    $previousStatus = trim($allStatus[$i]->status);
                    if (!in_array($previousStatus, ["INVOICE_PAID", "INVOICE_PARTIAL"])) {
                        return $previousStatus;
                    }
                }
                return "INVOICE_READY";
            }
        }
    }
    
    if (count($allStatus) > 1) {
        $previousStatus = trim($allStatus[1]->status);
        return $previousStatus;
    }
    
    return "INVOICE_DRAFT";
}

$router->run();
