<?php

use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $id = $_GET["id"] ?? null;

    if (!$id) {
        die("❌ Order ID not provided.");
    }

    $ordersRepo = new OrdersRepository();
    $historyRepo = new OrdersStatusHistoryRepository();

    $order = $ordersRepo->getByIdWithoutOwnershipCheck((int)$id);

  

    $history = $historyRepo->getAllBy(["id_order" => (int)$id]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "history" => $history
    ]); 
});

$router->run();
