<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesShoppingListRepository;

$router = new Router();

function suborders_list_path(): string {
    return 'panel/planner-hub/management/orders/orders/suborders/';
}

$router->get(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $assignedRepo = new OrderSuborderServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();
    $shoppingRepo = new OrdersServicesShoppingListRepository();

    $suborderId = (int)($_GET['id'] ?? 0);
    if ($suborderId <= 0) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    $order = $orderRepo->getOne([
        'id' => $suborder->id_order,
        'id_owner' => $user->getOwner(),
    ]);

    if (!$order) {
        MessageUtil::setMessage('❌ Sub-order not accessible.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    $servicesAssigned = $assignedRepo->getAllBy(['id_suborder' => $suborderId]);
    $services = [];

    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $items = $shoppingRepo->getBySuborderAndService($suborderId, $assigned->id_service);

        $services[] = [
            'id_service' => $assigned->id_service,
            'name' => $service->name,
            'items' => $items
        ];
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'id' => $suborderId,
        'suborder' => $suborder,
        'order' => $order,
        'services' => $services
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $suborderRepo = new OrdersSuborderRepository();
    $shoppingRepo = new OrdersServicesShoppingListRepository();

    $suborderId = (int)($_POST['suborder_id'] ?? $_GET['id'] ?? 0);

    if ($suborderId <= 0) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::reload();
    }

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::reload();
    }

    $data = $_POST['shopping'] ?? [];

    foreach ($data as $serviceId => $items) {
        $shoppingRepo->deleteBySuborderAndService($suborderId, $serviceId);

        foreach ($items as $entry) {
            if (trim($entry['item']) === '') continue;

            $shoppingRepo->add([
                'id_order' => $suborder->id_order,
                'id_suborder' => $suborderId,
                'id_service' => $serviceId,
                'item' => trim($entry['item']),
                'quantity' => trim($entry['quantity'] ?? ''),
                'notes' => trim($entry['notes'] ?? '')
            ]);
        }
    }

    MessageUtil::setMessage('✅ Shopping list saved successfully.');
    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
