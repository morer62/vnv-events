<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $repo = new OrdersRepository();
    $clientRepo = new UserRepository();

    $search = $_GET["search"] ?? null;
    $startDate = $_GET["start_date"] ?? null;
    $endDate = $_GET["end_date"] ?? null;
    $page = (int)($_GET["page"] ?? 1);
    $perPage = 10;
    $offset = ($page - 1) * $perPage;

    // Obtener órdenes previas (eventos que ya pasaron por fecha) con paginación
    $orders = $repo->getPastOrdersWithPagination($user->getId(), $search, $startDate, $endDate, $perPage, $offset);
    $totalOrders = $repo->getPastOrdersCount($user->getId(), $search, $startDate, $endDate);
    $totalPages = ceil($totalOrders / $perPage);

    $clients = $clientRepo->getAllBy(["level" => 5]);

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";

    foreach ($orders as &$order) {
        $payload = [
            "order_id" => (int)$order->id,
            "user_id" => (int)$order->id_client,
            "exp" => time() + (86400 * 30)
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode($payload), $secret);
        $order->contract_token = base64_encode(json_encode($payload));
    }

    $suborderRepo = new \App\Repositories\OrdersSuborderRepository();
    $paymentRepo = new \App\Repositories\OrdersPaymentsRepository();
    $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
    $suborderAssignedRepo = new \App\Repositories\OrderSuborderServicesAssignedRepository();
    $serviceRepo = new \App\Repositories\ServiceRepository();
    
    foreach ($orders as &$o) {
        $suborders = $suborderRepo->getByOrder($o->id);
        $o->_has_suborders = is_array($suborders) && count($suborders) > 0;

        $mainOrderPayments = $paymentRepo->getAllBy(["id_order" => $o->id]);
        $mainOrderPaid = 0.0;
        foreach ($mainOrderPayments as $p) {
            if (empty($p->id_suborder) || $p->id_suborder == 0) {
                $paid = isset($p->amount) ? (float)$p->amount : 0.0;
                $refunded = isset($p->refunded_amount) ? (float)$p->refunded_amount : 0.0;
                $mainOrderPaid += max(0.0, $paid - $refunded);
            }
        }
        
        $assigned = $assignedRepo->getAllBy(["id_order" => $o->id]);
        $subtotalMain = 0.0;
        foreach ($assigned as $a) {
            if (isset($a->subtotal) && $a->subtotal > 0) {
                $subtotalMain += (float)$a->subtotal;
            } else {
                $service = $serviceRepo->getOne(["id" => $a->id_service]);
                if ($service) {
                    $unitPrice = ($a->is_variable === 'YES' && $a->variable_price !== null) 
                        ? (float)$a->variable_price 
                        : (float)$service->price;
                    $subtotalMain += (float)$a->quantity * $unitPrice;
                }
            }
        }
        $discountMain = (float)($o->discount_value ?? 0);
        $baseMain = max($subtotalMain - $discountMain, 0);
        $taxMain = $baseMain * ((float)($o->tax_percentage ?? 0) / 100);
        $totalMain = round($baseMain + $taxMain, 2);
        
        try {
            $db = new \App\Repositories\Connection();
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
            $db->bind(":id", $o->id);
            $db->execute();
            $row = $db->fetchAll()[0] ?? null;
            $sumAdvances = (float)($row->total_advanced ?? 0);
        } catch (\Throwable $e) {
            $sumAdvances = 0;
        }
        $remainingMain = max($totalMain - $mainOrderPaid - $sumAdvances, 0);
        $o->_main_order_is_paid = ($remainingMain <= 0.01 && $totalMain > 0);
        $o->_main_order_has_first_payment = ($mainOrderPaid + $sumAdvances > 0 && !$o->_main_order_is_paid);
        
        if (!$o->_has_suborders) {
            $o->_effective_workflow = $o->status_workflow;
            
            $statusDetails = [];
            if (!$o->_main_order_is_paid && $totalMain > 0.01) {
                $paymentCount = 0;
                foreach ($mainOrderPayments as $p) {
                    if (empty($p->id_suborder) || $p->id_suborder == 0) {
                        $paymentCount++;
                    }
                }
                
                if ($o->payment_split_type == 2) {
                    if ($paymentCount == 0) {
                        $firstPercent = $o->payment_split_percent_1 ?? 50;
                        $firstAmount = round($totalMain * $firstPercent / 100, 2);
                        $firstPending = max($firstAmount - $sumAdvances, 0);
                        if ($firstPending > 0.01) {
                            $statusDetails[] = "First payment ($" . number_format($firstPending, 2) . ") pending";
                        }
                    } elseif ($paymentCount == 1) {
                        $remaining = max($totalMain - $mainOrderPaid - $sumAdvances, 0);
                        if ($remaining > 0.01) {
                            $statusDetails[] = "Second payment ($" . number_format($remaining, 2) . ") pending";
                        }
                    }
                } elseif ($o->payment_split_type == 1) {
                    if ($paymentCount == 0) {
                        $fullPending = max($totalMain - $sumAdvances, 0);
                        if ($fullPending > 0.01) {
                            $statusDetails[] = "Full payment ($" . number_format($fullPending, 2) . ") pending";
                        }
                    }
                }
            }
            $o->_status_info = $statusDetails;
            continue;
        }

        $suborderInfo = [];
        $lowestSuborderStatus = null;
        $lowestSuborderRank = 999;
        $hasUnpaidSuborder = false;
        $hasPaidSuborder = false;
        
        $rank = [
            'INVOICE_DRAFT' => 1,
            'INVOICE_READY' => 2,
            'INVOICE_PARTIAL' => 3,
            'INVOICE_PAID' => 4,
        ];
        
        foreach ($suborders as $sub) {
            $suborderServices = $suborderAssignedRepo->getServicesWithDetails($sub->id);
            $subtotalSub = 0.0;
            foreach ($suborderServices as $s) {
                $subtotalSub += (float)$s->quantity * (float)$s->actual_price;
            }
            $discountSub = (float)($sub->discount_value ?? 0);
            $baseSub = max($subtotalSub - $discountSub, 0);
            $taxSub = $baseSub * ((float)($sub->tax_percertance ?? 0) / 100);
            $totalSub = round($baseSub + $taxSub, 2);
            
            $subPayments = $paymentRepo->getAllBy(["id_suborder" => $sub->id]);
            $subPaid = 0.0;
            foreach ($subPayments as $p) {
                $paid = isset($p->amount) ? (float)$p->amount : 0.0;
                $refunded = isset($p->refunded_amount) ? (float)$p->refunded_amount : 0.0;
                $subPaid += max(0.0, $paid - $refunded);
            }
            
            try {
                $db = new \App\Repositories\Connection();
                $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
                $db->bind(":id", $sub->id);
                $db->execute();
                $row = $db->fetchAll()[0] ?? null;
                $sumAdvancesSub = (float)($row->total_advanced ?? 0);
            } catch (\Throwable $e) {
                $sumAdvancesSub = 0;
            }
            
            $remainingSub = max($totalSub - $subPaid - $sumAdvancesSub, 0);
            $isSubPaid = ($remainingSub <= 0.01 && $totalSub > 0);
            
            if ($isSubPaid) {
                $hasPaidSuborder = true;
            } else {
                $hasUnpaidSuborder = true;
            }
            
            $subStatus = $sub->status_workflow ?? 'INVOICE_READY';
            
            $r = $rank[$subStatus] ?? 4;
            if ($r < $lowestSuborderRank) {
                $lowestSuborderStatus = $subStatus;
                $lowestSuborderRank = $r;
            }
            
            $suborderInfo[] = [
                'id' => $sub->id,
                'total' => $totalSub,
                'paid' => $subPaid + $sumAdvancesSub,
                'remaining' => $remainingSub,
                'is_paid' => $isSubPaid,
                'status_workflow' => $subStatus
            ];
        }
        
        $o->_suborder_info = $suborderInfo;
        
        if ($hasPaidSuborder && !$o->_main_order_is_paid) {
            $o->_effective_workflow = $o->status_workflow;
        } elseif ($o->_main_order_is_paid) {
            if ($lowestSuborderStatus && $hasUnpaidSuborder) {
                $o->_effective_workflow = $lowestSuborderStatus;
            } else {
                $o->_effective_workflow = 'INVOICE_PAID';
            }
        } else {
            $mainRank = $rank[$o->status_workflow] ?? 4;
            if ($lowestSuborderRank < $mainRank) {
                $o->_effective_workflow = $lowestSuborderStatus;
            } else {
                $o->_effective_workflow = $o->status_workflow;
            }
        }
        
        $statusDetails = [];
        
        if ($o->_has_suborders) {
            foreach ($suborderInfo as $info) {
                if (!$info['is_paid'] && $info['remaining'] > 0.01) {
                    $statusDetails[] = "Suborder #{$info['id']}: $" . number_format($info['remaining'], 2) . " pending";
                }
            }
        }
        
        if (!$o->_main_order_is_paid && $totalMain > 0.01) {
            $paymentCount = 0;
            foreach ($mainOrderPayments as $p) {
                if (empty($p->id_suborder) || $p->id_suborder == 0) {
                    $paymentCount++;
                }
            }
            
            if ($o->payment_split_type == 2) {
                if ($paymentCount == 0) {
                    $firstPercent = $o->payment_split_percent_1 ?? 50;
                    $firstAmount = round($totalMain * $firstPercent / 100, 2);
                    $firstPending = max($firstAmount - $sumAdvances, 0);
                    if ($firstPending > 0.01) {
                        $statusDetails[] = "Main Order: First payment ($" . number_format($firstPending, 2) . ") pending";
                    }
                } elseif ($paymentCount == 1) {
                    $remaining = max($totalMain - $mainOrderPaid - $sumAdvances, 0);
                    if ($remaining > 0.01) {
                        $statusDetails[] = "Main Order: Second payment ($" . number_format($remaining, 2) . ") pending";
                    }
                }
            } elseif ($o->payment_split_type == 1) {
                if ($paymentCount == 0) {
                    $fullPending = max($totalMain - $sumAdvances, 0);
                    if ($fullPending > 0.01) {
                        $statusDetails[] = "Main Order: Full payment ($" . number_format($fullPending, 2) . ") pending";
                    }
                }
            }
        }
        
        $o->_status_info = $statusDetails;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "clients" => $clients,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "totalOrders" => $totalOrders,
        "perPage" => $perPage
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();

    if (isset($_POST['archive_order_id'])) {
        $id = (int)$_POST['archive_order_id'];
        $repo = new OrdersRepository();
        
        // Verificar que la orden existe y pertenece al usuario
        $order = $repo->getOne(['id' => $id]);
        if (!$order || $order->id_user != $session->getId()) {
            MessageUtil::setMessage("Order not found or access denied.");
            LocationUtils::reload();
            return;
        }
        
        $result = $repo->update(['is_archived' => 1], ['id' => $id]);
        
        if ($result) {
            MessageUtil::setMessage("Order #$id archived successfully.");
        } else {
            MessageUtil::setMessage("Failed to archive order #$id.");
        }
        
        LocationUtils::reload();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_manual_advance') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $amount = (float)($_POST['advance_amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if (!$orderId || $amount <= 0) {
            MessageUtil::setMessage("Invalid data provided.", "Error", "error");
            LocationUtils::reload();
        }

        $orderRepo = new OrdersRepository();
        $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
        if (!$order) {
            MessageUtil::setMessage("Order not found.", "Error", "error");
            LocationUtils::reload();
        }
        if (is_array($order)) {
            $order = (object)$order;
        }

        if ($order->status_workflow === 'INVOICE_DRAFT') {
            MessageUtil::setMessage("Cannot add advance to unsigned order.", "Error", "error");
            LocationUtils::reload();
        }

        $db = new \App\Repositories\Connection();
        
        $sumAdvances = 0;
        try {
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
            $db->bind(":id", $orderId);
            $db->execute();
            $row = $db->fetchAll()[0] ?? null;
            $sumAdvances = (float)($row->total_advanced ?? 0);
        } catch (\Throwable $e) {
            $sumAdvances = 0;
        }

        $assignedRepo = new \App\Repositories\OrdersServicesAssignedRepository();
        $serviceRepo = new \App\Repositories\ServiceRepository();
        $assigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
        $subtotalCalculated = 0;
        foreach ($assigned as $a) {
            if (isset($a->subtotal) && $a->subtotal > 0) {
                $subtotalCalculated += (float)$a->subtotal;
            } else {
                $service = $serviceRepo->getOne(["id" => $a->id_service]);
                if ($service) {
                    $unitPrice = ($a->is_variable === 'YES' && $a->variable_price !== null) 
                        ? (float)$a->variable_price 
                        : (float)$service->price;
                    $subtotalCalculated += (float)$a->quantity * $unitPrice;
                }
            }
        }
        $discountValue = (float)($order->discount_value ?? 0);
        $base = max($subtotalCalculated - $discountValue, 0);
        $taxRate = (float)($order->tax_percentage ?? 0);
        $tax = $base * ($taxRate / 100);
        $total = round($base + $tax, 2);

        $sumPaid = 0;
        try {
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
            $db->bind(":id", $orderId);
            $db->execute();
            $row = $db->fetchAll()[0] ?? null;
            $sumPaid = (float)($row->total_paid ?? 0);
        } catch (\Throwable $e) {
            $sumPaid = 0;
        }

        $remainingBefore = max($total - $sumAdvances - $sumPaid, 0);

        if ($amount > $remainingBefore) {
            MessageUtil::setMessage("Amount cannot exceed remaining balance of $" . number_format($remainingBefore, 2), "Error", "error");
            LocationUtils::reload();
        }

        try {
            $db->query("INSERT INTO orders_advances (id_order, is_suborder, id_suborder, amount, total_before, total_after, note, created_at) VALUES (:order_id, 0, NULL, :amount, :before, :after, :note, NOW())");
            $db->bind(":order_id", $orderId);
            $db->bind(":amount", $amount);
            $db->bind(":before", $remainingBefore);
            $db->bind(":after", max($remainingBefore - $amount, 0));
            $db->bind(":note", substr($note, 0, 255));
            $db->execute();
        } catch (\Throwable $e) {
            MessageUtil::setMessage("Failed to save advance: " . $e->getMessage(), "Error", "error");
            LocationUtils::reload();
        }

        $sumAdvancesAfter = $sumAdvances + $amount;
        $totalPaid = $sumAdvancesAfter + $sumPaid;

        $firstPercent = $order->payment_split_percent_1 ?? 50;
        $firstAmount = round($total * $firstPercent / 100, 2);
        $secondAmount = round($total - $firstAmount, 2);

        $currentStatus = $order->status_workflow ?? 'INVOICE_READY';

        if ($totalPaid >= $total - 0.01) {
            if ($currentStatus !== 'INVOICE_PAID') {
                $orderRepo->update([
                    'status_workflow' => 'INVOICE_PAID'
                ], ['id' => $orderId]);
                
                try {
                    $db->query("INSERT INTO orders_status_history (id_order, id_suborder, status, action_type, note, created_by, created_at) VALUES (:id_order, NULL, :status, :action_type, :note, :created_by, :created_at)");
                    $db->bind(":id_order", $orderId);
                    $db->bind(":status", "INVOICE_PAID");
                    $db->bind(":action_type", "manual_advance_complete");
                    $db->bind(":note", "Order fully paid through manual advance of $" . number_format($amount, 2));
                    $db->bind(":created_by", $session->getId());
                    $db->bind(":created_at", date("Y-m-d H:i:s"));
                    $db->execute();
                } catch (\Throwable $e) {
                    error_log("[MANUAL_ADVANCE][POST] Error adding status history: " . $e->getMessage());
                }
            }
        } elseif ($totalPaid >= $firstAmount - 0.01 && $currentStatus !== 'INVOICE_PAID') {
            if ($currentStatus !== 'INVOICE_PARTIAL') {
                $orderRepo->update([
                    'status_workflow' => 'INVOICE_PARTIAL'
                ], ['id' => $orderId]);
                
                try {
                    $db->query("INSERT INTO orders_status_history (id_order, id_suborder, status, action_type, note, created_by, created_at) VALUES (:id_order, NULL, :status, :action_type, :note, :created_by, :created_at)");
                    $db->bind(":id_order", $orderId);
                    $db->bind(":status", "INVOICE_PARTIAL");
                    $db->bind(":action_type", "manual_advance_first");
                    $db->bind(":note", "First payment completed through manual advance of $" . number_format($amount, 2));
                    $db->bind(":created_by", $session->getId());
                    $db->bind(":created_at", date("Y-m-d H:i:s"));
                    $db->execute();
                } catch (\Throwable $e) {
                    error_log("[MANUAL_ADVANCE][POST] Error adding status history: " . $e->getMessage());
                }
            }
        } elseif ($totalPaid >= $firstAmount + $secondAmount - 0.01 && $currentStatus !== 'INVOICE_PAID') {
            $orderRepo->update([
                'status_workflow' => 'INVOICE_PAID'
            ], ['id' => $orderId]);
            
            try {
                $db->query("INSERT INTO orders_status_history (id_order, id_suborder, status, action_type, note, created_by, created_at) VALUES (:id_order, NULL, :status, :action_type, :note, :created_by, :created_at)");
                $db->bind(":id_order", $orderId);
                $db->bind(":status", "INVOICE_PAID");
                $db->bind(":action_type", "manual_advance_second");
                $db->bind(":note", "Second payment completed through manual advance of $" . number_format($amount, 2));
                $db->bind(":created_by", $session->getId());
                $db->bind(":created_at", date("Y-m-d H:i:s"));
                $db->execute();
            } catch (\Throwable $e) {
                error_log("[MANUAL_ADVANCE][POST] Error adding status history: " . $e->getMessage());
            }
        }

        MessageUtil::setMessage("Advance of $" . number_format($amount, 2) . " added successfully.");
        LocationUtils::reload();
    }
});

$router->run();
