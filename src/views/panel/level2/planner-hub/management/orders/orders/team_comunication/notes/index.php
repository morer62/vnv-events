<?php

use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesNotesRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $orderId = $_GET["id"] ?? null;
    if (!$orderId) LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");

    $user = LoginService::getSession();

    $assignedRepo = new OrdersServicesAssignedRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $serviceRepo = new OrdersServiceRepository();
    $notesRepo = new OrdersServicesNotesRepository();

    // Servicios de la orden principal
    $servicesAssigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
    $services = [];

    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getOne(["id" => $assigned->id_service]);
        $note = $notesRepo->findByAssignedId($assigned->id, false);

        $services[] = [
            "id_assigned" => $assigned->id,
            "id_service" => $assigned->id_service,
            "name" => $service->name,
            "notes" => $note ? ($note->notes ?? '') : '',
            "has_manual_entry" => $note->has_manual_entry ?? 0,
            "install_time" => $note->install_time ?? '',
            "execution_time" => $note->execution_time ?? '',
            "breakdown_time" => $note->breakdown_time ?? '',
            "source" => "main_order"
        ];
    }

    // Servicios de las subórdenes
    $suborders = $suborderRepo->getByOrder($orderId);
    $suborderServices = [];
    
    foreach ($suborders as $suborder) {
        $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        
        foreach ($suborderServicesAssigned as $assigned) {
            $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
            $note = $notesRepo->findByAssignedId($assigned->id, true);

            $suborderServices[] = [
                "id_assigned" => $assigned->id,
                "id_service" => $assigned->id_service,
                "name" => $service ? $service->name : ($assigned->service_name ?? "Unknown Service"),
                "notes" => $note->notes ?? '',
                "has_manual_entry" => $note->has_manual_entry ?? 0,
                "install_time" => $note->install_time ?? '',
                "execution_time" => $note->execution_time ?? '',
                "breakdown_time" => $note->breakdown_time ?? '',
                "source" => "suborder",
                "suborder_id" => $suborder->id
            ];
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order_id" => $orderId,
        "services" => $services,
        "suborderServices" => $suborderServices,
        "suborders" => $suborders
    ]);
});

$router->post(function () {
    $orderId = $_POST["order_id"] ?? null;
    if (!$orderId) {
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    $notes = $_POST["notes"] ?? [];
    $manuals = $_POST["manual"] ?? [];
    $noteSource = $_POST["note_source"] ?? [];
    $suborderIds = $_POST["suborder_id"] ?? [];
    $assignedIds = $_POST["assigned_id"] ?? [];

    $installTimes = $_POST["install_time"] ?? [];
    $executionTimes = $_POST["execution_time"] ?? [];
    $breakdownTimes = $_POST["breakdown_time"] ?? [];

    $repo = new OrdersServicesNotesRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();

    $allKeys = array_unique(array_merge(
        array_keys($notes),
        array_keys($noteSource),
        array_keys($installTimes),
        array_keys($executionTimes),
        array_keys($breakdownTimes),
        array_keys($assignedIds),
        $manuals
    ));

    foreach ($allKeys as $key) {
        $hasManual = in_array($key, $manuals) ? 1 : 0;
        $source = $noteSource[$key] ?? "main_order";
        $suborderId = $suborderIds[$key] ?? null;
        $assignedId = $assignedIds[$key] ?? null;

        $data = [
            "notes" => trim($notes[$key] ?? ''),
            "has_manual_entry" => $hasManual,
            "install_time" => !empty($installTimes[$key]) ? $installTimes[$key] : null,
            "execution_time" => !empty($executionTimes[$key]) ? $executionTimes[$key] : null,
            "breakdown_time" => !empty($breakdownTimes[$key]) ? $breakdownTimes[$key] : null
        ];

        if ($source === "suborder" && $suborderId) {
            $id_service = null;
            if ($assignedId) {
                $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
                $subAssigned = $suborderServicesRepo->getAllBy(["id" => $assignedId]);
                if (!empty($subAssigned)) {
                    $id_service = $subAssigned[0]->id_service;
                }
            }
            
            if ($id_service) {
                $existing = $repo->findByAssignedId($assignedId, true);
                if ($existing) {
                    $repo->updateWithAssignedId($data, $assignedId, true);
                } else {
                    $addData = [
                        "id_suborder" => $suborderId,
                        "id_service" => $id_service,
                        ...$data
                    ];
                    $repo->addWithAssignedId($addData, $assignedId, true);
                }
            }
        } else {
            $id_service = null;
            if ($assignedId) {
                $assigned = $assignedRepo->getAllBy(["id" => $assignedId]);
                if (!empty($assigned)) {
                    $id_service = $assigned[0]->id_service;
                }
            }
            
            if ($id_service) {
                $existing = $repo->findByAssignedId($assignedId, false);
                if ($existing) {
                    $repo->updateWithAssignedId($data, $assignedId, false);
                } else {
                    $addData = [
                        "id_order" => $orderId,
                        "id_service" => $id_service,
                        ...$data
                    ];
                    $repo->addWithAssignedId($addData, $assignedId, false);
                }
            }
        }
    }

    LocationUtils::reload();
});

$router->run();
