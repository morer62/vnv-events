<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $userRepo = new UserRepository();

    $orderId = $_GET["id"] ?? null;
    if (!$orderId) {
        MessageUtil::setMessage("Order ID is required.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $orderRepo->getOneByIdAndOwner($orderId, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $orderRepo->getOne(["id" => $orderId]);
        if ($order && $order->id_owner != $user->getOwner()) {
            $order = null;
        }
    }
    
    if (!$order) {
        MessageUtil::setMessage("Order not found or access denied.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    $suborders = $suborderRepo->getByOrder($orderId);
    
    // Obtener detalles de servicios para cada sub-orden, calcular pagos y generar tokens de pago
    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $paymentsRepo = new OrdersPaymentsRepository();
    foreach ($suborders as $suborder) {
        $suborder->services = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        
        // Generar token para pago de suborden
        $payload = [
            "suborder_id" => $suborder->id,
            "user_id" => $order->id_client,
            "exp" => time() + (86400 * 30) // 30 días
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode($payload), $secret);
        $suborder->payment_token = base64_encode(json_encode($payload));

        // Calcular total de la suborden usando precios reales y tax
        $subtotalCalculated = 0;
        foreach ($suborder->services as $service) {
            // En getServicesWithDetails, el precio final está en actual_price
            $subtotalCalculated += ((float)$service->quantity) * ((float)$service->actual_price);
        }
        $taxRate = isset($suborder->tax_percertance) ? (float)$suborder->tax_percertance : 0.0;
        $taxAmount = $subtotalCalculated * ($taxRate / 100.0);
        $totalAmount = round($subtotalCalculated + $taxAmount, 2);

        // Sumar pagos asociados a esta suborden
        $payments = $paymentsRepo->getAllBy(["id_order" => $orderId, "id_suborder" => $suborder->id]);
        $amountPaid = 0.0;
        foreach ($payments as $p) {
            // considerar solo pagos no reembolsados completamente
            $paid = isset($p->amount) ? (float)$p->amount : 0.0;
            $refunded = isset($p->refunded_amount) ? (float)$p->refunded_amount : 0.0;
            $amountPaid += max(0.0, $paid - $refunded);
        }
        $balanceDue = max(0.0, round($totalAmount - $amountPaid, 2));

        // Anexar métricas a la suborden para mostrarlas en la vista
        $suborder->total_amount = $totalAmount;
        $suborder->amount_paid = round($amountPaid, 2);
        $suborder->balance_due = $balanceDue;
        if ($totalAmount <= 0.0) {
            $suborder->payment_status = 'N/A';
        } elseif ($balanceDue <= 0.0) {
            $suborder->payment_status = 'PAID';
        } elseif ($amountPaid > 0.0) {
            $suborder->payment_status = 'PARTIAL';
        } else {
            $suborder->payment_status = 'PENDING';
        }
    }

    $clients = $userRepo->getAllAssociatedClients($user->getOwner());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "suborders" => $suborders,
        "clients" => $clients
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $suborderRepo = new OrdersSuborderRepository();

    if (isset($_POST['archive_suborder_id'])) {
        $id = (int)$_POST['archive_suborder_id'];
        $suborderRepo->update(['is_archived' => 1], ['id' => $id]);

        MessageUtil::setMessage("Sub-order deleted successfully.");
        LocationUtils::reload();
    }
});

$router->run();
