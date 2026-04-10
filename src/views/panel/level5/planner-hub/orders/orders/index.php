<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\ClientsUsersRepository;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $repo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $paymentsRepo = new OrdersPaymentsRepository();
    $profileRepo = new InstitutionProfileRepository();
    $clientsRepo = new ClientsUsersRepository();

    // Usar el nuevo método que no filtra por owner
    $orders = $repo->getOrdersForClientWithoutOwnerFilter($user->getId());

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    
    foreach ($orders as &$order) {
        $order->institution = $profileRepo->getByOwner($order->id_owner);
        
        // Token para orden principal
        $payload = [
            "order_id" => (int)$order->id,
            "user_id" => (int)$order->id_client,
            "exp" => time() + (86400 * 30)
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode([
            "order_id" => $payload["order_id"],
            "user_id" => $payload["user_id"],
            "exp" => $payload["exp"]
        ]), $secret);
        $order->contract_token = base64_encode(json_encode($payload));
        
        // Obtener subórdenes
        $suborders = $suborderRepo->getByOrder($order->id);
        $order->suborders = [];
        
        foreach ($suborders as $suborder) {
            // Obtener servicios de la suborden
            $suborder->services = $suborderServicesRepo->getServicesWithDetails($suborder->id);
            
            // Generar token para pago de suborden
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
            
            // Calcular total de la suborden
            $subtotalCalculated = 0;
            foreach ($suborder->services as $service) {
                $subtotalCalculated += ((float)$service->quantity) * ((float)$service->actual_price);
            }
            $taxRate = isset($suborder->tax_percertance) ? (float)$suborder->tax_percertance : 0.0;
            $taxAmount = $subtotalCalculated * ($taxRate / 100.0);
            $totalAmount = round($subtotalCalculated + $taxAmount, 2);
            
            // Calcular pagos realizados
            $payments = $paymentsRepo->getAllBy(["id_order" => $order->id, "id_suborder" => $suborder->id]);
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
            
            $order->suborders[] = $suborder;
        }
    }

    $appUrl = $_ENV["APP_URL"] ?? "http://localhost/vnv-venue";
    $appUrl = rtrim($appUrl, '/');
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "app_url" => $appUrl
    ]);
});

$router->run();