<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
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
    $docsRepo = new DocumentsLogsRepository();
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
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
    
    $appUrl = $_ENV["APP_URL"] ?? "http://localhost/vnv-venue";
    $appUrl = rtrim($appUrl, '/');
    $today = date('Y-m-d');
    $currentOrders = [];
    $pastOrders = [];

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
        $order->public_order_url = $appUrl . '/order-access?token=' . urlencode($order->contract_token);
        $order->has_signed_contract = in_array((string)($order->status_workflow ?? ''), ['INVOICE_READY', 'INVOICE_PARTIAL', 'INVOICE_PAID'], true)
            || (bool)$docsRepo->getByType((int)$order->id, 'contract_signed');
        $order->has_signed_acceptance = (bool)$docsRepo->getByType((int)$order->id, 'order_acceptance_signed')
            || (bool)$acceptanceRepo->getByOrder((int)$order->id);
        $order->client_action_label = vnv_client_order_action_label($order);
        $order->client_action_class = vnv_client_order_action_class($order);
        $order->client_status_label = vnv_client_order_status_label($order);
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

        $eventDate = substr((string)($order->event_date ?? ''), 0, 10);
        if ($eventDate !== '' && $eventDate < $today) {
            $pastOrders[] = $order;
        } else {
            $currentOrders[] = $order;
        }
    }
    unset($order);
    
    $mobileOwnerId = (int)($_ENV['MOBILE_OWNER_ID'] ?? 0);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "current_orders" => $currentOrders,
        "past_orders" => $pastOrders,
        "app_url" => $appUrl,
        "mobile_owner_id" => $mobileOwnerId,
        "clientContext" => $clientContext,
    ]);
});

$router->run();

function vnv_client_order_action_label(object $order): string
{
    $status = (string)($order->status_workflow ?? '');
    if (!$order->has_signed_contract || $status === 'INVOICE_DRAFT') {
        return 'Click to Sign Contract';
    }

    if (in_array($status, ['INVOICE_READY', 'INVOICE_PARTIAL'], true)) {
        return 'Click to Pay';
    }

    if ($status === 'INVOICE_PAID' && empty($order->has_signed_acceptance)) {
        return 'Click to Sign as Received';
    }

    return 'Click to View Details';
}

function vnv_client_order_action_class(object $order): string
{
    $label = vnv_client_order_action_label($order);
    return match ($label) {
        'Click to Sign Contract' => 'btn-warning text-dark',
        'Click to Pay' => 'btn-success',
        'Click to Sign as Received' => 'btn-info',
        default => 'btn-outline-primary',
    };
}

function vnv_client_order_status_label(object $order): string
{
    return match ((string)($order->status_workflow ?? '')) {
        'INVOICE_DRAFT' => 'Signature Pending',
        'INVOICE_READY' => 'Payment Pending',
        'INVOICE_PARTIAL' => 'Partially Paid',
        'INVOICE_PAID' => empty($order->has_signed_acceptance) ? 'Receipt Signature Pending' : 'Completed',
        'INVOICE_EXPIRED' => 'Expired',
        default => 'Order Details',
    };
}
