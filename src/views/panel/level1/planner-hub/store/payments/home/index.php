<?php

use App\Repositories\StoreOrdersRepository;
use App\Repositories\StorePaymentsRepository;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();

    $paymentsRepo = new StorePaymentsRepository();
    $ordersRepo = new StoreOrdersRepository();

    $status = strtoupper(trim($_GET['status'] ?? ''));
    $method = strtolower(trim($_GET['method'] ?? ''));
    $email = trim($_GET['email'] ?? '');
    $orderIdFilter = (int)($_GET['order_id'] ?? 0);

    $payments = $paymentsRepo->getAllByOwner($ownerId, 300);

    if ($status !== '') {
        $payments = array_values(array_filter($payments, function ($p) use ($status) {
            return strtoupper((string)($p->status ?? '')) === $status;
        }));
    }

    if ($method !== '') {
        $payments = array_values(array_filter($payments, function ($p) use ($method) {
            return strtolower((string)($p->payment_method ?? '')) === $method;
        }));
    }

    if ($email !== '') {
        $payments = array_values(array_filter($payments, function ($p) use ($email) {
            return stripos((string)($p->payer_email ?? ''), $email) !== false;
        }));
    }

    if ($orderIdFilter > 0) {
        $payments = array_values(array_filter($payments, function ($p) use ($orderIdFilter) {
            return (int)($p->id_store_order ?? 0) === $orderIdFilter;
        }));
    }

    $orderCache = [];
    foreach ($payments as &$payment) {
        $orderId = (int)($payment->id_store_order ?? 0);
        if ($orderId > 0 && !isset($orderCache[$orderId])) {
            $orderCache[$orderId] = $ordersRepo->getById($orderId);
        }
        $order = $orderCache[$orderId] ?? null;
        $payment->order_public_token = $order->public_token ?? '';
        $payment->order_customer_name = $order->guest_name ?? '';
        $payment->order_customer_email = $order->guest_email ?? '';
    }
    unset($payment);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $payments,
        "filters" => [
            "status" => $status,
            "method" => $method,
            "email" => $email,
            "order_id" => $orderIdFilter > 0 ? (string)$orderIdFilter : ''
        ]
    ]);
});

$router->run();

