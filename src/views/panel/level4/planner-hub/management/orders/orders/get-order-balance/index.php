<?php

use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\ServiceRepository;
use App\Utils\JsonResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $orderId = (int)($_GET['id'] ?? 0);
    if (!$orderId) {
        return JsonResponse::createResponse(["success" => false, "error" => "Invalid order ID"]);
    }

    $user = LoginService::getSession();
    if (!$user) {
        return JsonResponse::createResponse(["success" => false, "error" => "Unauthorized"]);
    }

    $orderRepo = new OrdersRepository();
    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if (!$order) {
        return JsonResponse::createResponse(["success" => false, "error" => "Order not found"]);
    }
    if (is_array($order)) {
        $order = (object)$order;
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
        error_log("[GET_ORDER_BALANCE] Error getting advances: " . $e->getMessage());
        $sumAdvances = 0;
    }

    $assignedRepo = new OrdersServicesAssignedRepository();
    $serviceRepo = new ServiceRepository();
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
        error_log("[GET_ORDER_BALANCE] Error getting payments: " . $e->getMessage());
        $sumPaid = 0;
    }

    $remainingBalance = max($total - $sumAdvances - $sumPaid, 0);

    error_log("[GET_ORDER_BALANCE] Order ID: {$orderId}, Subtotal: {$subtotalCalculated}, Discount: {$discountValue}, Tax: {$tax}, Total: {$total}, Advances: {$sumAdvances}, Paid: {$sumPaid}, Remaining: {$remainingBalance}");

    return JsonResponse::createResponse([
        "success" => true,
        "remaining_balance" => $remainingBalance,
        "total" => $total,
        "sum_advances" => $sumAdvances,
        "sum_paid" => $sumPaid,
        "subtotal_calculated" => $subtotalCalculated,
        "discount_value" => $discountValue,
        "tax" => $tax
    ]);
});

$router->run();

