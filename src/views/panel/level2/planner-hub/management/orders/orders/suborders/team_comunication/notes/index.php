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
use App\Repositories\OrdersServicesNotesRepository;

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
    $notesRepo = new OrdersServicesNotesRepository();

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
        $service = $serviceRepo->getOne(['id' => $assigned->id_service]);
        $note = $notesRepo->findBySuborderAndService($suborderId, $assigned->id_service);

        $services[] = [
            'id_service' => $assigned->id_service,
            'name' => $service->name,
            'notes' => $note->notes ?? '',
            'has_manual_entry' => $note->has_manual_entry ?? 0,
            'install_time' => $note->install_time ?? '',
            'execution_time' => $note->execution_time ?? '',
            'breakdown_time' => $note->breakdown_time ?? ''
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
    $notesRepo = new OrdersServicesNotesRepository();

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

    $notes = $_POST['notes'] ?? [];
    $manuals = $_POST['manual'] ?? [];
    $installTimes = $_POST['install_time'] ?? [];
    $executionTimes = $_POST['execution_time'] ?? [];
    $breakdownTimes = $_POST['breakdown_time'] ?? [];

    foreach ($notes as $id_service => $text) {
        $hasManual = in_array($id_service, $manuals) ? 1 : 0;

        $data = [
            'notes' => trim($text),
            'has_manual_entry' => $hasManual,
            'install_time' => $installTimes[$id_service] ?? null,
            'execution_time' => $executionTimes[$id_service] ?? null,
            'breakdown_time' => $breakdownTimes[$id_service] ?? null
        ];

        $existing = $notesRepo->findBySuborderAndService($suborderId, $id_service);

        if ($existing) {
            $notesRepo->update($data, ['id' => $existing->id]);
        } else {
            $notesRepo->add([
                'id_order' => $suborder->id_order,
                'id_suborder' => $suborderId,
                'id_service' => $id_service,
                ...$data
            ]);
        }
    }

    MessageUtil::setMessage('✅ Notes saved successfully.');
    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
