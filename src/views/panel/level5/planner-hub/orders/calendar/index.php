<?php

use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Services\LoginService;
use App\Services\OrdersCalendarService;
use App\Services\TranslationService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $service = new OrdersCalendarService();
    $profileRepo = new InstitutionProfileRepository();
    $ordersRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $assignedServicesRepo = new OrdersServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();
    $paymentsRepo = new OrdersPaymentsRepository();
    $tasksRepo = new OrdersTeamTasksRepository();

    $weekStart = $service->normalizeWeekStart($_GET['week'] ?? null);
    $search = trim((string)($_GET['search'] ?? ''));
    $orders = $ordersRepo->getOrdersForClientWithCompany((int)$user->getId());
    $orderIds = array_values(array_unique(array_map(static fn($order) => (int)$order->id, $orders)));
    $teamContactsByOrder = [];
    if (!empty($orderIds)) {
        $ordersByOwner = [];
        foreach ($orders as $order) {
            $ownerId = (int)($order->id_owner ?? 0);
            if ($ownerId > 0) {
                $ordersByOwner[$ownerId][] = (int)$order->id;
            }
        }
        foreach ($ordersByOwner as $ownerId => $ownerOrderIds) {
            $teamContactsByOrder += $tasksRepo->getAssigneesByOrders((int)$ownerId, $ownerOrderIds);
        }
    }

    foreach ($orders as $order) {
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
        if (empty($order->institution->company_name)) {
            $order->institution = $profileRepo->getByOwner((int)($order->id_owner ?? 0));
        }

        vnv_calendar_attach_client_order_details(
            $order,
            $suborderRepo,
            $suborderServicesRepo,
            $assignedServicesRepo,
            $serviceRepo,
            $paymentsRepo,
            $teamContactsByOrder[(int)$order->id] ?? []
        );
    }

    if ($search !== '') {
        $needle = strtolower($search);
        $orders = array_values(array_filter($orders, function ($order) use ($needle) {
            $haystack = strtolower(trim(
                (string)($order->address ?? '') . ' ' .
                (string)($order->institution->company_name ?? '') . ' ' .
                (string)($order->status_workflow ?? '')
            ));

            return str_contains($haystack, $needle);
        }));
    }

    $calendar = $service->buildClientCalendar($orders, $weekStart);

    return TemplateResponse::render(__DIR__ . '/../../../../shared/orders-calendar/index.twig', [
        'calendar' => $calendar,
        'search' => $search,
        'listUrl' => '/panel/planner-hub/orders/orders',
        'calendarUrl' => '/panel/planner-hub/orders/calendar',
    ]);
});

$router->run();

function vnv_calendar_attach_client_order_details(
    object $order,
    OrdersSuborderRepository $suborderRepo,
    OrderSuborderServicesAssignedRepository $suborderServicesRepo,
    OrdersServicesAssignedRepository $assignedServicesRepo,
    OrdersServiceRepository $serviceRepo,
    OrdersPaymentsRepository $paymentsRepo,
    array $teamContacts
): void {
    $orderId = (int)($order->id ?? 0);
    $suborders = $suborderRepo->getByOrder($orderId);
    $suborderPayloads = [];
    $allPayments = [];
    $totals = [
        'subtotal' => 0.0,
        'discount' => 0.0,
        'tax' => 0.0,
        'total' => 0.0,
        'paid' => 0.0,
        'balance' => 0.0,
    ];

    foreach ($suborders as $suborder) {
        $services = [];
        $subtotal = 0.0;
        foreach ($suborderServicesRepo->getServicesWithDetails($suborder->id) as $service) {
            $quantity = (float)($service->quantity ?? 1);
            $unitPrice = (float)($service->actual_price ?? $service->unit_price ?? $service->service_price ?? 0);
            $lineTotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineTotal;
            $services[] = [
                'name' => (string)($service->service_name ?? 'Service'),
                'description' => (string)($service->service_description ?? ''),
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'subtotal' => $lineTotal,
            ];
        }

        $discountValue = (float)($suborder->discount_value ?? 0);
        $discountType = (string)($suborder->discount_type ?? 'amount');
        $discount = $discountType === 'percent' ? $subtotal * ($discountValue / 100.0) : $discountValue;
        $taxRate = (float)($suborder->tax_percertance ?? 0);
        $tax = max(0.0, $subtotal - $discount) * ($taxRate / 100.0);
        $total = round(max(0.0, $subtotal - $discount) + $tax, 2);

        $payments = $paymentsRepo->getAllBy(['id_order' => $orderId, 'id_suborder' => (int)$suborder->id]);
        $paid = 0.0;
        $paymentPayloads = [];
        foreach ($payments as $payment) {
            $paymentPayload = vnv_calendar_payment_payload($payment, (int)$suborder->id);
            $paid += $paymentPayload['netAmount'];
            $paymentPayloads[] = $paymentPayload;
            $allPayments[] = $paymentPayload;
        }

        $balance = max(0.0, round($total - $paid, 2));
        $suborderPayloads[] = [
            'id' => (int)$suborder->id,
            'status' => (string)($suborder->status_workflow ?? 'INVOICE_READY'),
            'services' => $services,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => $total,
            'paid' => round($paid, 2),
            'balance' => $balance,
            'payments' => $paymentPayloads,
        ];

        $totals['subtotal'] += $subtotal;
        $totals['discount'] += $discount;
        $totals['tax'] += $tax;
        $totals['total'] += $total;
        $totals['paid'] += $paid;
        $totals['balance'] += $balance;
    }

    if (empty($suborderPayloads)) {
        $services = [];
        $subtotal = 0.0;
        foreach ($assignedServicesRepo->getAllWithoutOwner(['id_order' => $orderId]) as $assigned) {
            $service = !empty($assigned->id_service) ? $serviceRepo->getByIdWithoutOwnershipCheck((int)$assigned->id_service) : null;
            $quantity = (float)($assigned->quantity ?? 1);
            $unitPrice = (float)($assigned->unit_price ?? 0);
            if ($unitPrice <= 0) {
                $unitPrice = (float)(($assigned->is_variable ?? '') === 'YES' && $assigned->variable_price !== null
                    ? $assigned->variable_price
                    : ($service->price ?? $assigned->service_price ?? 0));
            }
            $lineTotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineTotal;
            $services[] = [
                'name' => (string)($service->name ?? $assigned->description ?? 'Service'),
                'description' => (string)($assigned->description ?? $service->description ?? ''),
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'subtotal' => $lineTotal,
            ];
        }

        $discountValue = (float)($order->discount_value ?? 0);
        $discountType = (string)($order->discount_type ?? 'amount');
        $discount = $discountType === 'percentage' ? $subtotal * ($discountValue / 100.0) : $discountValue;
        $taxRate = (float)($order->tax_percentage ?? 0);
        $tax = max(0.0, $subtotal - $discount) * ($taxRate / 100.0);
        $total = round(max(0.0, $subtotal - $discount) + $tax, 2);

        foreach ($paymentsRepo->getAllByOrder($orderId) as $payment) {
            $paymentPayload = vnv_calendar_payment_payload($payment, null);
            $totals['paid'] += $paymentPayload['netAmount'];
            $allPayments[] = $paymentPayload;
        }

        $totals['subtotal'] = $subtotal;
        $totals['discount'] = $discount;
        $totals['tax'] = $tax;
        $totals['total'] = $total;
        $totals['balance'] = max(0.0, $total - $totals['paid']);

        if (!empty($services) || !empty($allPayments)) {
            $suborderPayloads[] = [
                'id' => null,
                'status' => (string)($order->status_workflow ?? 'ORDER'),
                'services' => $services,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax' => round($tax, 2),
                'total' => $total,
                'paid' => round($totals['paid'], 2),
                'balance' => round($totals['balance'], 2),
                'payments' => $allPayments,
            ];
        }
    }

    foreach ($totals as $key => $value) {
        $totals[$key] = round((float)$value, 2);
    }

    $chatContacts = [];
    foreach ($teamContacts as $member) {
        if ((int)($member->level ?? 0) !== 4 || (int)($member->allow_chat_with_clients ?? 0) !== 1) {
            continue;
        }
        $name = trim((string)(($member->name ?? '') . ' ' . ($member->lastname ?? '')));
        $chatContacts[] = [
            'id' => (int)$member->id,
            'name' => $name !== '' ? $name : (string)($member->email ?? TranslationService::trans('client_service_orders.team_member')),
            'email' => (string)($member->email ?? ''),
            'url' => LocationUtils::pathFor('panel/chat?to=' . (int)$member->id),
        ];
    }

    $order->calendar_suborders = $suborderPayloads;
    $order->calendar_payments = $allPayments;
    $order->calendar_totals = $totals;
    $order->calendar_chat_contacts = $chatContacts;
}

function vnv_calendar_payment_payload(object $payment, ?int $suborderId): array
{
    $amount = (float)($payment->amount ?? 0);
    $refunded = (float)($payment->refunded_amount ?? 0);
    $card = trim((string)($payment->card_brand ?? '') . ' ' . (!empty($payment->card_last4) ? 'ending ' . $payment->card_last4 : ''));
    $status = ((int)($payment->is_refunded ?? 0) === 1 || $refunded > 0) ? 'REFUNDED' : 'PAID';

    return [
        'id' => (int)($payment->id ?? 0),
        'suborderId' => $suborderId,
        'amount' => round($amount, 2),
        'refundedAmount' => round($refunded, 2),
        'netAmount' => round(max(0.0, $amount - $refunded), 2),
        'method' => (string)($payment->method ?? $payment->provider_type ?? 'payment'),
        'provider' => (string)($payment->provider_type ?? ''),
        'concept' => (string)($payment->payment_concept ?? ''),
        'paidAt' => (string)($payment->paid_at ?? $payment->created_at ?? ''),
        'card' => $card,
        'status' => $status,
    ];
}
