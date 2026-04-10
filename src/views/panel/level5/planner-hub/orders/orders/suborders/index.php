<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $paymentsRepo = new OrdersPaymentsRepository();
    $profileRepo = new InstitutionProfileRepository();

    $orderId = $_GET["id"] ?? null;
    if (!$orderId) {
        MessageUtil::setMessage("Order ID is required.");
        LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
    }

    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if ($order) {
        $order = (object)$order;
    }
    
    if (!$order || $order->id_client != $user->getId()) {
        MessageUtil::setMessage("Order not found or access denied.");
        LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
    }

    $suborders = $suborderRepo->getByOrder($orderId);
    
    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    
    foreach ($suborders as $suborder) {
        $suborder->services = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        
        $subPayload = [
            "suborder_id" => $suborder->id,
            "user_id" => $order->id_client,
            "exp" => time() + (86400 * 30)
        ];
        $subPayload["hash"] = hash_hmac("sha256", json_encode([
            "suborder_id" => $subPayload["suborder_id"],
            "user_id" => $subPayload["user_id"],
            "exp" => $subPayload["exp"]
        ]), $secret);
        $suborder->payment_token = base64_encode(json_encode($subPayload));

        $subtotalCalculated = 0;
        foreach ($suborder->services as $service) {
            $subtotalCalculated += ((float)$service->quantity) * ((float)$service->actual_price);
        }
        $taxRate = isset($suborder->tax_percertance) ? (float)$suborder->tax_percertance : 0.0;
        $taxAmount = $subtotalCalculated * ($taxRate / 100.0);
        $totalAmount = round($subtotalCalculated + $taxAmount, 2);

        $payments = $paymentsRepo->getAllBy(["id_order" => $orderId, "id_suborder" => $suborder->id]);
        $amountPaid = 0.0;
        foreach ($payments as $p) {
            $paid = isset($p->amount) ? (float)$p->amount : 0.0;
            $refunded = isset($p->refunded_amount) ? (float)$p->refunded_amount : 0.0;
            $amountPaid += max(0.0, $paid - $refunded);
        }
        $balanceDue = max(0.0, round($totalAmount - $amountPaid, 2));

        $suborder->total_amount = $totalAmount;
        $suborder->amount_paid = $amountPaid;
        $suborder->balance_due = $balanceDue;
    }

    $order->institution = $profileRepo->getByOwner($order->id_owner);

    $appUrl = $_ENV["APP_URL"] ?? "http://localhost/vnv-venue";
    $appUrl = rtrim($appUrl, '/');

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "suborders" => $suborders,
        "app_url" => $appUrl
    ]);
});

$router->run();
