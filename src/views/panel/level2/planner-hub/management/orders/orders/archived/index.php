<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $repo = new OrdersRepository();
    $clientRepo = new UserRepository();

    $search = $_GET["search"] ?? null;
    $startDate = $_GET["start_date"] ?? null;
    $endDate = $_GET["end_date"] ?? null;
    $page = (int)($_GET["page"] ?? 1);
    $perPage = 10;
    $offset = ($page - 1) * $perPage;

    // Obtener órdenes archivadas (archivadas manualmente) con paginación
    $orders = $repo->getArchivedOrders($user->getId(), $search, $startDate, $endDate, $perPage, $offset);
    $totalOrders = $repo->getArchivedOrdersCount($user->getId(), $search, $startDate, $endDate);
    $totalPages = ceil($totalOrders / $perPage);

    $clients = $clientRepo->getAllBy(["level" => 5]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "clients" => $clients,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "totalOrders" => $totalOrders,
        "perPage" => $perPage
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();

    if (isset($_POST['unarchive_order_id'])) {
        $id = (int)$_POST['unarchive_order_id'];
        $repo = new OrdersRepository();
        
        // Verificar que la orden existe y pertenece al usuario
        $order = $repo->getOne(['id' => $id]);
        if (!$order) {
            MessageUtil::setMessage("Order not found.");
            LocationUtils::reload();
            return;
        }
        
        if ($order->id_user != $session->getId()) {
            MessageUtil::setMessage("Access denied.");
            LocationUtils::reload();
            return;
        }
        
        if ($order->is_archived != 1) {
            MessageUtil::setMessage("Order is not archived (current status: " . $order->is_archived . ").");
            LocationUtils::reload();
            return;
        }
        
        $result = $repo->update(['is_archived' => 0], ['id' => $id]);
        
        if ($result) {
            MessageUtil::setMessage("Order #$id unarchived successfully.");
        } else {
            MessageUtil::setMessage("Failed to unarchive order #$id.");
        }
        
        LocationUtils::reload();
    }
});

$router->run();
