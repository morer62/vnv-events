<?php

use App\Repositories\OrdersRepository;
use App\Services\ArchivedOrderService;
use App\Services\LoginService;
use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $ownerId = (int)($user->getOwner() ?: $user->getId());
    $search = trim((string)($_GET['search'] ?? ''));
    $startDate = trim((string)($_GET['start_date'] ?? ''));
    $endDate = trim((string)($_GET['end_date'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $repo = new OrdersRepository();

    $orders = $repo->getArchivedOrdersForOwner($ownerId, $search ?: null, $startDate ?: null, $endDate ?: null, $perPage, $offset);
    $totalOrders = $repo->getArchivedOrdersForOwnerCount($ownerId, $search ?: null, $startDate ?: null, $endDate ?: null);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'orders' => $orders,
        'currentPage' => $page,
        'totalPages' => max(1, (int)ceil($totalOrders / $perPage)),
        'totalOrders' => $totalOrders,
        'perPage' => $perPage,
        'search' => $search,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'csrf_token' => CSRF::generateCSRF(),
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    CSRF::validateCSRF();
    $ownerId = (int)($session->getOwner() ?: $session->getId());
    $action = trim((string)($_POST['action'] ?? ''));
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        MessageUtil::setMessage('Order not found.', 'Archived Orders', 'error');
        LocationUtils::reload();
    }

    try {
        $service = new ArchivedOrderService();
        if ($action === 'unarchive') {
            if (!$service->unarchive($orderId, $ownerId)) {
                throw new RuntimeException('The order was not archived or is not available for this business.');
            }
            MessageUtil::setMessage("Order VNV341{$orderId} restored to active operations.");
        } elseif ($action === 'delete_permanently') {
            $service->permanentlyDelete($orderId, $ownerId, (string)($_POST['confirmation'] ?? ''));
            MessageUtil::setMessage("Order VNV341{$orderId} and its operational records were permanently deleted.");
        } else {
            throw new RuntimeException('Unsupported archived-order action.');
        }
    } catch (Throwable $e) {
        MessageUtil::setMessage($e->getMessage(), 'Archived Orders', 'error');
    }
    LocationUtils::reload();
});

$router->run();
