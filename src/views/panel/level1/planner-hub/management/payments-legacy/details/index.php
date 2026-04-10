<?php

use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $orderId = $_GET["id"] ?? null;

    if (!$orderId) LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");

    $orderRepo = new OrdersRepository();
    $paymentRepo = new OrdersPaymentsRepository();

    $order = $orderRepo->getOne(["id" => $orderId]);

    if (!$order || $order->id_owner !== $user->getOwner()) {
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    $payments = $paymentRepo->getAllByOrder($orderId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "payments" => $payments
    ]);
});

$router->run();