<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Services\UserWorkspaceContextService;
use App\Services\TranslationService;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $repo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $paymentsRepo = new OrdersPaymentsRepository();
    $tasksRepo = new OrdersTeamTasksRepository();
    $workspaceContextService = new UserWorkspaceContextService();
    $clientContext = $workspaceContextService->getClientContext($user);
    $orders = $repo->getOrdersForClientWithCompany((int)$user->getId());

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $orderIds = array_values(array_unique(array_map(static fn($order) => (int)$order->id, $orders)));
    $teamContactsByOrder = [];
    if (!empty($orderIds)) {
        $contactsByOwner = [];
        foreach ($orders as $order) {
            $ownerId = (int)($order->id_owner ?? 0);
            if ($ownerId > 0) {
                $contactsByOwner[$ownerId][] = (int)$order->id;
            }
        }
        foreach ($contactsByOwner as $ownerId => $ownerOrderIds) {
            $teamContactsByOrder += $tasksRepo->getAssigneesByOrders((int)$ownerId, $ownerOrderIds);
        }
    }
    
    foreach ($orders as &$order) {
        $order->institution = (object)[
            'company_name' => $order->company_name ?? null,
            'logo_path' => $order->company_logo_path ?? null,
            'email' => $order->company_email ?? null,
            'phone' => $order->company_phone ?? null,
            'address_line1' => $order->company_address_line1 ?? null,
            'city' => $order->company_city ?? null,
            'state' => $order->company_state ?? null,
            'zip' => $order->company_zip ?? null,
            'country' => $order->company_country ?? null,
        ];
        $teamContacts = [];
        foreach ($teamContactsByOrder[(int)$order->id] ?? [] as $member) {
            if ((int)($member->level ?? 0) !== 4 || (int)($member->allow_chat_with_clients ?? 0) !== 1) {
                continue;
            }
            $teamContacts[] = [
                'id' => (int)$member->id,
                'name' => trim((string)(($member->name ?? '') . ' ' . ($member->lastname ?? ''))) ?: ($member->email ?? TranslationService::trans('client_service_orders.team_member')),
                'email' => $member->email ?? '',
            ];
        }
        $order->team_contacts_json = json_encode($teamContacts);
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
        $suborders = $suborderRepo->getByOrder($order->id);
        $order->suborders = [];
        
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
    
    $mobileOwnerId = (int)($_ENV['MOBILE_OWNER_ID'] ?? 0);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "app_url" => $appUrl,
        "mobile_owner_id" => $mobileOwnerId,
        "clientContext" => $clientContext,
    ]);
});

$router->run();
