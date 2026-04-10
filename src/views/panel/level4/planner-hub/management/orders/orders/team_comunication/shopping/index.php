<?php

use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesShoppingListRepository;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $orderId = $_GET["id"] ?? null;
    if (!$orderId) LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");

    $assignedRepo = new OrdersServicesAssignedRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $serviceRepo = new OrdersServiceRepository();
    $shoppingRepo = new OrdersServicesShoppingListRepository();

    // Servicios de la orden principal
    $servicesAssigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
    $services = [];

    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $items = $shoppingRepo->getByOrderAndService($orderId, $assigned->id_service);

        $services[] = [
            "id_service" => $assigned->id_service,
            "name" => $service ? $service->name : "Unknown Service",
            "items" => $items,
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
            $items = $shoppingRepo->getBySuborderAndService($suborder->id, $assigned->id_service);

            $suborderServices[] = [
                "id_service" => $assigned->id_service,
                "name" => $service ? $service->name : ($assigned->service_name ?? "Unknown Service"),
                "items" => $items,
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
    if (!$orderId) LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");

    $repo = new OrdersServicesShoppingListRepository();
    $data = $_POST["shopping"] ?? [];
    $shoppingKeys = $_POST["shopping_keys"] ?? [];

    foreach ($data as $shoppingKey => $items) {
        if (!isset($shoppingKeys[$shoppingKey])) {
            continue;
        }
        
        $keyInfo = $shoppingKeys[$shoppingKey];
        $source = $keyInfo["source"] ?? "main_order";
        $serviceId = $keyInfo["service_id"] ?? null;
        $suborderId = $keyInfo["suborder_id"] ?? null;

        if (!$serviceId) {
            continue;
        }

        if ($source === "suborder" && $suborderId) {
            // Items de suborden
            $repo->deleteBySuborderAndService($suborderId, $serviceId);

            foreach ($items as $entry) {
                if (trim($entry["item"]) === "") continue;

                $repo->add([
                    "id_suborder" => $suborderId,
                    "id_service" => $serviceId,
                    "item" => trim($entry["item"]),
                    "quantity" => trim($entry["quantity"] ?? ''),
                    "notes" => trim($entry["notes"] ?? '')
                ]);
            }
        } else {
            // Items de orden principal
            $repo->deleteByOrderAndService($orderId, $serviceId);

            foreach ($items as $entry) {
                if (trim($entry["item"]) === "") continue;

                $repo->add([
                    "id_order" => $orderId,
                    "id_service" => $serviceId,
                    "item" => trim($entry["item"]),
                    "quantity" => trim($entry["quantity"] ?? ''),
                    "notes" => trim($entry["notes"] ?? '')
                ]);
            }
        }
    }

    LocationUtils::reload();
});

$router->run();
