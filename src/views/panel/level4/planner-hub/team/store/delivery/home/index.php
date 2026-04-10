<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Repositories\StoreOrderWorkflowRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrdersRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\FileUtils;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    $storeUserRolesRepo = new StoreUserRolesRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $itemsRepo = new StoreOrderItemsRepository();

    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitution = null;
    $storeTeamRole = 'general';
    $ownerId = 0;

    if ($currentInstitutionId) {
        $currentInstitution = $institutionRepo->getById($currentInstitutionId);
    }

    if (!$currentInstitution) {
        $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
        if ($primaryInstitution) {
            $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
            $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
        }
    }

    if ($currentInstitution) {
        $ownerId = (int)($currentInstitution->id_owner ?? 0);
        if ($ownerId > 0) {
            $storeTeamRole = $storeUserRolesRepo->getRoleValueByOwnerAndUser($ownerId, (int)$user->getId()) ?: 'general';
        }
    }

    $orders = [];
    if ($ownerId > 0) {
        $orders = $workflowRepo->getDeliveryOrders($ownerId, (int)$user->getId());
        foreach ($orders as &$order) {
            $shippingParts = array_filter([
                trim((string)($order->shipping_address_1 ?? '')),
                trim((string)($order->shipping_city ?? '')),
                trim((string)(
                    trim((string)($order->shipping_state ?? '')) .
                    (((string)($order->shipping_zip ?? '') !== '') ? (' ' . trim((string)$order->shipping_zip)) : '')
                ))
            ], function ($v) {
                return $v !== '';
            });
            $order->shipping_address_display = $shippingParts
                ? implode(', ', $shippingParts)
                : ((string)($order->city ?? '') !== '' ? (string)$order->city : '—');

            $items = $itemsRepo->getByOrder((int)$order->id);
            $order->items_resume = array_map(function ($item) {
                return ($item->product_name_snapshot ?? '#') . ' x ' . (int)($item->quantity ?? 0);
            }, $items);
        }
        unset($order);
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'user' => $user,
        'currentInstitution' => $currentInstitution,
        'storeTeamRole' => $storeTeamRole,
        'orders' => $orders
    ]);
});

$router->post(function () {
    $workflowRepo = new StoreOrderWorkflowRepository();
    $ordersRepo = new StoreOrdersRepository();

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_sending_bulk') {
        $ids = $_POST['order_ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            MessageUtil::setMessage('Select at least one order.');
            LocationUtils::reload();
        }

        $okAny = false;
        foreach ($ids as $id) {
            $orderId = (int)$id;
            if ($orderId <= 0) {
                continue;
            }
            $okWf = $workflowRepo->markSending($orderId);
            $okStatus = $ordersRepo->updateStatus($orderId, StoreOrdersRepository::STATUS_SENDING);
            if ($okWf && $okStatus) {
                $okAny = true;
            }
        }
        MessageUtil::setMessage($okAny ? 'Selected orders marked as sending.' : 'Could not update selected orders.');
        LocationUtils::reload();
    }

    if ($action === 'mark_delivered') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        if ($orderId <= 0) {
            MessageUtil::setMessage('Invalid order.');
            LocationUtils::reload();
        }
        $photoUrl = null;
        if (FileUtils::hasFile($_FILES, 'delivery_photo')) {
            try {
                $photoUrl = FileUtils::saveFile($_FILES['delivery_photo'], 'store-delivery-proof');
            } catch (Exception $e) {
                MessageUtil::setMessage('Could not upload delivery photo.');
                LocationUtils::reload();
            }
        }
        $notes = trim($_POST['delivery_notes'] ?? '');
        $okWf = $workflowRepo->markDelivered($orderId, $photoUrl, $notes !== '' ? $notes : null);
        $okStatus = $ordersRepo->updateStatus($orderId, StoreOrdersRepository::STATUS_DELIVERED);
        MessageUtil::setMessage(($okWf && $okStatus) ? 'Order marked as delivered.' : 'Could not mark order delivered.');
        LocationUtils::reload();
    }

    MessageUtil::setMessage('Invalid action.');
    LocationUtils::reload();
});

$router->run();