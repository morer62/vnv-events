<?php

use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersPaymentSplitRepository;
use App\Services\NotificationService;
use App\Repositories\TipsRepository;



$router = new Router();

$router->get(callback: function () {
    $context = UserContext::get();

   

    $repo = new OrdersRepository();
    $usersRepo = new UserRepository();
    $contractsRepo = new OrdersContractRepository();
    $servicesRepo = new OrdersServiceRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $docRepo = new \App\Repositories\DocumentsLogsRepository();
    $tipsRepo = new TipsRepository();
    $user = LoginService::getSession();

    $id = $_GET["id"] ?? null;
    if (!$id) LocationUtils::redirectInternal("panel/orders/home");

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            
            if ($institution && $institution->id_owner) {
                $order = $repo->getOneByIdAndOwner($id, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $repo->getOne(["id" => $id]);
        
        if ($order && $order->id_user != $user->getId() && $order->id_owner != $user->getOwner()) {
            $order = null;
        }
    }

    if (!$order) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    // Verificar si el contrato ya está firmado
    $docs = $docRepo->getAllBy(["id_order" => $order->id]);
    $contractSigned = false;
    foreach ($docs as $doc) {
        if ($doc->doc_type === 'contract_signed') {
            $contractSigned = true;
            break;
        }
    }

    // Verificar si la orden puede ser modificada basándose en el estado de pago
    $canModify = true;
    $modificationMessage = "";
    $limitedEdit = false; // permitir editar solo fecha, dirección, hora de inicio y contrato
    $contractEditable = !$contractSigned; // No editar contrato si ya está firmado
    
    if ($order->status_workflow === 'INVOICE_PARTIAL' || $order->status_workflow === 'INVOICE_PAID') {
        $canModify = false;
        $limitedEdit = true;
        $modificationMessage = "⚠️ This order has received payments. Only date, address, start time, end time, tip, and notes can be edited. Contract cannot be modified.";
        $contractEditable = false;
    } elseif ($contractSigned) {
        $modificationMessage = "⚠️ The contract has been signed. Contract selection cannot be modified.";
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $clients = $usersRepo->getAllAssociatedClients($institution->id_owner);
                $contracts = $contractsRepo->getAllByInstitutionOwner($institution->id_owner);
                $services = $servicesRepo->getAllByInstitutionOwner($institution->id_owner, 0);
            } else {
                $clients = [];
                $contracts = [];
                $services = [];
            }
        } else {
            $clients = [];
            $contracts = [];
            $services = [];
        }
    } else {
        $clients = $usersRepo->getAllAssociatedClients($user->getOwner());
        $contracts = $contractsRepo->getAllBy([...LoginService::getOwnerAsArray()]);
        $services = $servicesRepo->getAllBy([...LoginService::getOwnerAsArray(), "is_archived" => 0]);
    }

    $tips = $tipsRepo->getActiveTips();
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
            ...$context,
            "order" => $order,
            "clients" => $clients,
            "contracts" => $contracts,
            "services" => $services,
            "tips" => $tips,
            "assigned" => $assignedRepo->getAllBy([
                "id_order" => $order->id
            ]),
            "payment_statuses" => OrdersRepository::PAYMENT_STATUSES,
            "canModify" => $canModify,
            "limitedEdit" => $limitedEdit,
            "contractEditable" => $contractEditable,
            "contractSigned" => $contractSigned,
            "modificationMessage" => $modificationMessage
        ]);

});

$router->post(function () {
    $context = UserContext::get();

    $id = $_POST["id"] ?? null;
    $repo = new OrdersRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $docRepo = new \App\Repositories\DocumentsLogsRepository();
    $user = LoginService::getSession();
    $splitRepo = new OrdersPaymentSplitRepository();
    $split = $splitRepo->getByOrder($id);

    // Verificar si la orden puede ser modificada
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $repo->getOneByIdAndOwner($id, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $repo->getOne(["id" => $id]);
        if ($order && $order->id_user != $user->getId() && $order->id_owner != $user->getOwner()) {
            $order = null;
        }
    }
    
    if (!$order) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    // Verificar si el contrato ya está firmado
    $docs = $docRepo->getAllBy(["id_order" => $order->id]);
    $contractSigned = false;
    foreach ($docs as $doc) {
        if ($doc->doc_type === 'contract_signed') {
            $contractSigned = true;
            break;
        }
    }

    // Si ya se ha recibido pago, permitir edición limitada (fecha, dirección, hora inicio, contrato)
    $isLimited = ($order->status_workflow === 'INVOICE_PARTIAL' || $order->status_workflow === 'INVOICE_PAID');

    // Si hay split registrado y es "split"
    if ($split && $split->split_type === 'split') {
        $totalNew = $repo->calculateTotal($id); // según los nuevos servicios
        $alreadyPaid = $split->first_amount;

        if ($totalNew <= $alreadyPaid) {
            // Mostrar opción: "Marcar como pagado completo"
            $canClose = true;
        } else {
            $newSecondAmount = $totalNew - $alreadyPaid;
            $splitRepo->updateSecondAmount($id, $newSecondAmount);
        }
    }

    $totalTeamNeeded = isset($_POST["total_team_needed"]) ? (int) $_POST["total_team_needed"] : 0;

    // Calcular el descuento real si no es edición limitada
    $actual_discount_value = $_POST["discount_value"] ?? 0;
    if (!$isLimited) {
        // Calcular el subtotal de los servicios
        $subtotal = 0;
        $services = json_decode($_POST["selectedServices"] ?? "[]", true);
        foreach ($services as $service) {
            $subtotal += (float)$service['subtotal'];
        }

        // Calcular el descuento real basado en el tipo
        $discount_type = $_POST["discount_type"] ?? "amount";
        if ($discount_type === 'percent') {
            $actual_discount_value = $subtotal * (($actual_discount_value) / 100);
        }
    }

    $id_tip = !empty($_POST["id_tip"]) ? $_POST["id_tip"] : null;
    
    if ($isLimited) {
        $data = [
            "event_date" => $_POST["event_date"],
            "start_time" => $_POST["start_time"],
            "end_time" => $_POST["end_time"],
            "address" => $_POST["address"],
            "notes" => $_POST["notes"] ?? "",
            "id_tip" => $id_tip,
        ];
    } else {
        // Normalizar método de pago (split)
        $paymentSplitType = (int)($_POST['payment_split_type'] ?? $order->payment_split_type ?? 1);
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

        $data = [
            "id_client" => $_POST["id_client"],
            "event_date" => $_POST["event_date"],
            "start_time" => $_POST["start_time"],
            "end_time" => $_POST["end_time"],
            "address" => $_POST["address"],
            "discount_type" => $_POST["discount_type"] ?? "amount",
            "discount_value" => $_POST["discount_value"] ?? 0,
            "tax_percentage" => $_POST["tax_percentage"] ?? 0,
            "id_tip" => $id_tip,
            "notes" => $_POST["notes"] ?? "",
            "total_team_needed" => $totalTeamNeeded,
            "payment_split_type" => $paymentSplitType,
            "payment_split_percent_1" => $paymentSplit1,
            "payment_split_percent_2" => $paymentSplit2,
        ];

        // Solo agregar contrato si es editable
        if (!$contractSigned) {
            $data["id_contract"] = $_POST["id_contract"];
        }
    }

    $repo->update($data, ["id" => $id]);

    // Actualizar servicios sólo si no es edición limitada
    if (!$isLimited) {
        // Primero, obtener los servicios existentes para preservar unit_price y description
        $existingServices = $assignedRepo->getAllBy(["id_order" => $id]);
        $existingPrices = [];
        $existingDescriptions = [];
        foreach ($existingServices as $existing) {
            // Guardar unit_price por id_service (usar el histórico si existe)
            $key = $existing->id_service;
            if (!isset($existingPrices[$key]) || $existing->unit_price > 0) {
                $existingPrices[$key] = $existing->unit_price > 0 
                    ? $existing->unit_price 
                    : ($existing->subtotal / max($existing->quantity, 1));
            }
            // Guardar description histórica si existe
            if (!isset($existingDescriptions[$key]) && isset($existing->description) && $existing->description) {
                $existingDescriptions[$key] = $existing->description;
            }
        }
        
        // Ahora eliminar y volver a insertar
        $assignedRepo->delete(["id_order" => $id]);
        $services = json_decode($_POST["selectedServices"] ?? "[]", true);
        $serviceRepo = new OrdersServiceRepository();
        
        foreach ($services as $item) {
            $serviceId = $item["service"];
            
            // Si el servicio ya existía, usar su precio y descripción históricos
            // Si es nuevo, usar el precio y descripción actuales del servicio
            if (isset($existingPrices[$serviceId])) {
                $unitPrice = $existingPrices[$serviceId];
                $description = $existingDescriptions[$serviceId] ?? null;
            } else {
                // Servicio nuevo: obtener precio y descripción actuales
                $service = $serviceRepo->getByIdWithoutOwnershipCheck($serviceId);
                $unitPrice = ($item["is_variable"] ?? "NO") === "YES" && isset($item["variable_price"]) 
                    ? $item["variable_price"] 
                    : ($service ? $service->price : 0);
                $description = $service ? ($service->description ?? null) : null;
            }
            
            $assignedRepo->add([
                "id_order" => $id,
                "id_service" => $serviceId,
                "quantity" => $item["qty"],
                "unit_price" => $unitPrice,
                "description" => $description,
                "subtotal" => $item["subtotal"],
                "id_owner" => $user->getOwner(),
                "is_variable" => $item["is_variable"] ?? "NO",
                "variable_price" => $item["variable_price"] ?? null
            ]);
        }
    }

    // 🔔 Notificar al cliente y al owner sobre la edición de la orden
    $order = $repo->getOne(["id" => $id]);
    NotificationService::sendToUsers(
        [$order->id_client, $order->id_owner],
        '🔧 Order Updated',
        'Order VNV 341' . $id . ' was updated. Please log in to your account to review the changes.'
    );

    MessageUtil::setMessage("✅ Order updated successfully!");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
});

$router->run();
