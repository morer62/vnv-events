<?php

use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\UserRepository;

$router = new Router();

$router->get(callback: function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();
    $userRepo = new UserRepository();

    $suborderId = $_GET["id"] ?? null;
    if (!$suborderId) {
        MessageUtil::setMessage("Sub-order ID is required.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    $suborder = $suborderRepo->getOne(["id" => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage("Sub-order not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    // Obtener la orden padre
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $parentOrder = $orderRepo->getOneByIdAndOwner($suborder->id_order, $institution->id_owner);
            } else {
                $parentOrder = null;
            }
        } else {
            $parentOrder = null;
        }
    } else {
        $parentOrder = $orderRepo->getOne(["id" => $suborder->id_order]);
        if ($parentOrder && $parentOrder->id_owner != $user->getOwner()) {
            $parentOrder = null;
        }
    }
    
    if (!$parentOrder) {
        MessageUtil::setMessage("Access denied.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    // Verificar si la sub-orden puede ser editada
    $canModify = true;
    $modificationMessage = "";
    
    // Verificar si la sub-orden está archivada
    if ($suborder->is_archived == 1) {
        $canModify = false;
        $modificationMessage = "⚠️ This sub-order has been archived and cannot be modified.";
    } else {
        // Verificar si la sub-orden tiene pagos (aquí puedes agregar la lógica cuando implementes pagos)
        // Por ahora, permitimos edición si no está archivada
        // En el futuro, agregarías algo como:
        // $paymentsRepo = new OrdersPaymentsRepository();
        // $payments = $paymentsRepo->getAllBy(['id_suborder' => $suborderId, 'is_suborder' => 'YES']);
        // if (count($payments) > 0) {
        //     $canModify = false;
        //     $modificationMessage = "⚠️ This sub-order has received payments and cannot be modified.";
        // }
    }

    // Obtener servicios asignados a la sub-orden
    $assignedServices = $suborderServicesRepo->getServicesWithDetails($suborderId);

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo_temp = new \App\Repositories\InstitutionProfileRepository();
            $institution_temp = $institutionRepo_temp->getById($currentInstitutionId);
            if ($institution_temp && $institution_temp->id_owner) {
                $services = $serviceRepo->getAllByInstitutionOwner($institution_temp->id_owner, 0);
            } else {
                $services = [];
            }
        } else {
            $services = [];
        }
    } else {
        $services = $serviceRepo->getAllBy([
            ...LoginService::getOwnerAsArray(),
            "is_archived" => 0,
        ]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "assignedServices" => $assignedServices,
        "services" => $services,
        "canModify" => $canModify,
        "modificationMessage" => $modificationMessage
    ]);
});

$router->post(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    
    $suborderId = $_POST["suborder_id"] ?? null;
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();

    if (!$suborderId) {
        MessageUtil::setMessage("Sub-order ID is required.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    $suborder = $suborderRepo->getOne(["id" => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage("Sub-order not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    // Verificar si puede ser modificada
    if ($suborder->is_archived == 1) {
        MessageUtil::setMessage("⚠️ This sub-order has been archived and cannot be modified.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/suborders?id=" . $suborder->id_order);
    }

    // Actualizar datos básicos de la sub-orden, incluyendo descuento
    $discountType = $_POST['discount_type'] ?? 'amount';
    $discountValue = (float)($_POST['discount_value'] ?? 0);
    
    // Calcular el descuento real si es porcentual
    $actual_discount_value = $discountValue;
    if ($discountType === 'percent') {
        // Calcular subtotal de servicios para aplicar porcentaje
        $services = json_decode($_POST["selectedServices"] ?? "[]", true);
        $subtotal = 0;
        foreach ($services as $service) {
            $subtotal += (float)$service['subtotal'];
        }
        $actual_discount_value = $subtotal * ($discountValue / 100);
    }
    
    // Corregir el tipo de descuento para la BD (debe ser 'percentage' no 'percent')
    $db_discount_type = ($discountType === 'percent') ? 'percentage' : 'amount';

    // Normalizar régimen de pago (split)
    $paymentSplitType = (int)($_POST['payment_split_type'] ?? 1);
    $paymentSplit1 = (int)($_POST['payment_split_percent_1'] ?? ($paymentSplitType === 1 ? 100 : 50));
    if ($paymentSplitType === 1) {
        $paymentSplit1 = 100;
        $paymentSplit2 = 0;
    } else {
        // Asegurar rango 1..99 y complementar a 100
        if ($paymentSplit1 < 1) { $paymentSplit1 = 1; }
        if ($paymentSplit1 > 99) { $paymentSplit1 = 99; }
        $paymentSplit2 = 100 - $paymentSplit1;
    }

    $updateData = [
        'tax_percertance' => $_POST['tax_percentage'] ?? 0,
        'payment_split_type' => $paymentSplitType,
        'payment_split_percent_1' => $paymentSplit1,
        'payment_split_percent_2' => $paymentSplit2,
        'discount_type' => $db_discount_type,
        'discount_value' => $actual_discount_value
    ];

    $suborderRepo->update($updateData, ['id' => $suborderId]);

    $existingServices = $suborderServicesRepo->getAllBy(["id_suborder" => $suborderId]);
    $existingPrices = [];
    $existingDescriptions = [];
    
    foreach ($existingServices as $existing) {
        $key = (int)$existing->id_service;
        
        if (!isset($existingPrices[$key])) {
            if (isset($existing->unit_price) && $existing->unit_price > 0) {
                $existingPrices[$key] = (float)$existing->unit_price;
            } elseif (isset($existing->subtotal) && isset($existing->quantity) && $existing->quantity > 0) {
                $existingPrices[$key] = (float)$existing->subtotal / (float)$existing->quantity;
            }
        }
        
        if (!isset($existingDescriptions[$key])) {
            if (isset($existing->description) && !empty(trim($existing->description))) {
                $existingDescriptions[$key] = $existing->description;
            }
        }
    }
    
    $suborderServicesRepo->delete(["id_suborder" => $suborderId]);

    $services = json_decode($_POST["selectedServices"] ?? "[]", true);
    $serviceRepo = new \App\Repositories\OrdersServiceRepository();
    
    foreach ($services as $item) {
        $serviceId = (int)$item["service"];
        
        if (isset($existingPrices[$serviceId])) {
            $unitPrice = $existingPrices[$serviceId];
            $description = $existingDescriptions[$serviceId] ?? null;
        } else {
            $service = $serviceRepo->getByIdWithoutOwnershipCheck($serviceId);
            
            if (($item["is_variable"] ?? "NO") === "YES" && isset($item["variable_price"]) && $item["variable_price"] > 0) {
                $unitPrice = (float)$item["variable_price"];
            } else {
                $unitPrice = $service ? (float)$service->price : 0;
            }
            
            $description = $service ? ($service->description ?? null) : null;
        }
        
        $quantity = (float)($item["qty"] ?? 1);
        $calculatedSubtotal = $unitPrice * $quantity;
        
        $suborderServicesRepo->add([
            "id_suborder" => $suborderId,
            "id_service" => $serviceId,
            "quantity" => $quantity,
            "unit_price" => $unitPrice,
            "description" => $description,
            "subtotal" => $calculatedSubtotal,
            "id_owner" => $user->getOwner(),
            "is_variable" => $item["is_variable"] ?? "NO",
            "variable_price" => $item["variable_price"] ?? null
        ]);
    }

    MessageUtil::setMessage("✅ Sub-order updated successfully!");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/suborders?id=" . $suborder->id_order);
});

$router->run();
