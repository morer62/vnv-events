<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersServicesNotesRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\VnvPDF;

$user = LoginService::getSession();

$orderRepo = new OrdersRepository();
$contractRepo = new OrdersContractRepository();
$userRepo = new UserRepository();
$assignedRepo = new OrdersServicesAssignedRepository();
$serviceRepo = new OrdersServiceRepository();
$taskRepo = new OrdersServiceTasksRepository();
$teamTaskRepo = new OrdersTeamTasksRepository();
$institutionRepo = new InstitutionProfileRepository();
$notesRepo = new OrdersServicesNotesRepository();

$orderId = $_GET["id"] ?? null;
if (!$orderId) LocationUtils::redirectInternal("panel/orders/home");

if ($user->getLevel() === 4) {
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    if ($currentInstitutionId) {
        $institutionRepo_temp = new InstitutionProfileRepository();
        $institution_temp = $institutionRepo_temp->getById($currentInstitutionId);
        if ($institution_temp && $institution_temp->id_owner) {
            $order = $orderRepo->getOneByIdAndOwner($orderId, $institution_temp->id_owner);
        } else {
            $order = null;
        }
    } else {
        $order = null;
    }
} else {
    $order = $orderRepo->getOne(["id" => $orderId, "id_owner" => $user->getOwner()]);
}

if (!$order) {
    MessageUtil::setMessage("Order not found.");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
}

$institution = $institutionRepo->getByOwner($order->id_owner);
$institution = json_decode(json_encode($institution), true);
$pdf = new VnvPDF($institution);

$suborderRepo = new OrdersSuborderRepository();
$suborderServicesRepo = new OrderSuborderServicesAssignedRepository();

$client = $userRepo->getOne(["id" => $order->id_client]);
$servicesAssigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
$manualTasks = $teamTaskRepo->getAllBy(["id_order" => $orderId, "id_service" => 0]);

$services = [];

// Servicios de la orden principal
foreach ($servicesAssigned as $assigned) {
    $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
    $requirements = $service->requirements ?? '';

    $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);

    foreach ($tasks as $task) {
        $assignedTask = $teamTaskRepo->getOne([
            "id_order" => $orderId,
            "id_service" => $assigned->id_service,
            "task_description" => $task->task_name
        ]);

        $task->assigned_id_user = $assignedTask?->id_user;
        $task->assigned_user_name = $assignedTask?->id_user
            ? $userRepo->getOne(["id" => $assignedTask->id_user])->name
            : null;
        $task->id_task = $assignedTask?->id;
    }

    $services[] = [
        "name" => $service->name,
        "id_service" => $assigned->id_service,
        "tasks" => $tasks,
        "quantity" => $assigned->quantity ?? 1,
        "requirements" => $requirements,
        "source" => "main_order",
        "suborder_id" => null
    ];
}

// Servicios de subórdenes
$suborders = $suborderRepo->getByOrder($orderId);
foreach ($suborders as $suborder) {
    $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
    
    foreach ($suborderServicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $requirements = $service->requirements ?? '';

        $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);

        foreach ($tasks as $task) {
            $assignedTask = $teamTaskRepo->getOne([
                "id_suborder" => $suborder->id,
                "id_service" => $assigned->id_service,
                "task_description" => $task->task_name
            ]);

            $task->assigned_id_user = $assignedTask?->id_user;
            $task->assigned_user_name = $assignedTask?->id_user
                ? $userRepo->getOne(["id" => $assignedTask->id_user])->name
                : null;
            $task->id_task = $assignedTask?->id;
        }

        $services[] = [
            "name" => $service->name,
            "id_service" => $assigned->id_service,
            "tasks" => $tasks,
            "quantity" => $assigned->quantity ?? 1,
            "requirements" => $requirements,
            "source" => "suborder",
            "suborder_id" => $suborder->id
        ];
    }
    
    // Tareas manuales de subórdenes
    $suborderManualTasks = $teamTaskRepo->getAllBy(["id_suborder" => $suborder->id, "id_service" => 0]);
    foreach ($suborderManualTasks as $manualTask) {
        $manualTask->suborder_id = $suborder->id;
    }
    $manualTasks = array_merge($manualTasks, $suborderManualTasks);
}

$totalServices = count($services);
$serviceIndex = 1;

foreach ($services as $assigned) {
    $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned["id_service"]);
    
    // Obtener notas según el origen (orden principal o suborden)
    if ($assigned["source"] === "suborder" && $assigned["suborder_id"]) {
        $noteEntry = $notesRepo->findBySuborderAndService($assigned["suborder_id"], $assigned["id_service"]);
    } else {
        $noteEntry = $notesRepo->findByOrderAndService($orderId, $assigned["id_service"]);
    }
    
    $hasManualEntry = $noteEntry?->has_manual_entry ?? 0;
    $customNote = $noteEntry?->notes ?? '';
    $tasks = $assigned["tasks"];
    $quantity = $assigned["quantity"];

    $pdf->AddPage();

    $pdf->SetXY(165, 10);
    $pdf->SetFont('Arial', 'B', 14);
    $serviceLabel = $assigned["source"] === "suborder" 
        ? "Sub-Order #{$assigned["suborder_id"]} - Service {$serviceIndex}/{$totalServices}"
        : "Service {$serviceIndex}/{$totalServices}";
    $pdf->Cell(30, 10, $serviceLabel, 0, 0, 'R');

    $pdf->Ln(25);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetFillColor(240, 240, 240);

    $fields = [
        ['Client:', "{$client->name} {$client->lastname}"],
        ['Phone:', $client->phone],
        ['Address:', $order->address],
        ['Event Date:', date("l, M j", strtotime($order->event_date)) . ' - Start ' . date("g:i A", strtotime($order->start_time)) . ' to ' . date("g:i A", strtotime($order->end_time))]
    ];

    foreach ($fields as $row) {
        $pdf->Cell(40, 7, $row[0], 1, 0, 'L', true);
        $pdf->Cell(150, 7, $row[1], 1, 1);
    }

    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 12, "{$service->name}   (Units: {$quantity})", 0, 1);

    if ($noteEntry) {
        $install = $noteEntry->install_time ? date("g:i A", strtotime($noteEntry->install_time)) : "--";
        $execution = $noteEntry->execution_time ? date("g:i A", strtotime($noteEntry->execution_time)) : "--";
        $breakdown = $noteEntry->breakdown_time ? date("g:i A", strtotime($noteEntry->breakdown_time)) : "--";

        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, "Installation Time: $install     Execution: $execution     Breakdown: $breakdown", 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    if (!empty($customNote)) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, "PREPARATION DETAILS", 1, 1, 'L', true);
        $pdf->SetFont('Arial', '', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(190, 10, utf8_decode($customNote));
        $pdf->Ln(3);
    }

    if (!empty($tasks)) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, "Staff Assignment", 1, 1, 'L', true);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(130, 8, 'Task', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'Assigned To', 1, 1, 'C', true);

        foreach ($tasks as $task) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(130, 8, utf8_decode($task->task_name), 1);
            $pdf->Cell(60, 8, $task->assigned_user_name ?? 'Not assigned', 1, 1);
        }

        $pdf->Ln(3);
    }

    if ($hasManualEntry) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 8, "Additional Instructions", 0, 1);
        $pdf->MultiCell(190, 15, '', 1);
    }

    $serviceIndex++;
}

if (!empty($manualTasks)) {
    $pdf->AddPage();
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, "ADDITIONAL ASSIGNMENTS", 1, 1, 'L', true);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(200, 220, 255);
    $pdf->Cell(130, 8, 'Task Name', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Assigned To', 1, 1, 'C', true);

    foreach ($manualTasks as $task) {
        $userName = $task->id_user ? $userRepo->getOne(["id" => $task->id_user])->name : "Not assigned";
        $taskSource = isset($task->suborder_id) ? " (Sub-Order #{$task->suborder_id})" : "";
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(130, 8, $task->task_description . $taskSource, 1);
        $pdf->Cell(60, 8, $userName, 1, 1);
    }

    $pdf->Ln(10);

    if (!empty($order->notes)) {
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Client Notes / Observations', 1, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln(2);
        $pdf->MultiCell(0, 8, utf8_decode($order->notes));
    }
}

// ✅ Nueva página para comentarios y requerimientos
$pdf->AddPage();

// Título principal con fondo negro
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 10, 'SERVICE REQUIREMENTS', 1, 1, 'L', true);

// Reset estilo para contenido
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);

foreach ($services as $svc) {
    $requirements = $svc["requirements"];

    if (!empty($requirements)) {
        $pdf->Ln(4);

        // Nombre del servicio
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, '* ' . $svc["name"], 0, 1);

        // Texto de requerimientos
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, utf8_decode($requirements));
    }
}


$pdf->Output();
