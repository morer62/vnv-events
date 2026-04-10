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
$suborderRepo = new OrdersSuborderRepository();
$suborderServicesRepo = new OrderSuborderServicesAssignedRepository();

$orderId = $_GET["id"] ?? null;

if (!$orderId) LocationUtils::redirectInternal("panel/orders/home");

$orderData = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
$order = $orderData ? (object)$orderData : null;

if (!$order) {
    die("Order not found");
}

$institution = $institutionRepo->getByOwner($order->id_owner);
$institution = json_decode(json_encode($institution), true);
$pdf = new VnvPDF($institution);

$client = $userRepo->getOne(["id" => $order->id_client]);

// Servicios de la orden principal
$servicesAssigned = $assignedRepo->getAllWithoutOwner(["id_order" => $orderId]);
$manualTasks = $teamTaskRepo->getAllWithoutOwner(["id_order" => $orderId, "id_service" => 0]);

// Obtener todas las subórdenes de esta orden
$allSuborders = $suborderRepo->getByOrder($orderId);

$services = [];

// Procesar servicios de la orden principal
foreach ($servicesAssigned as $assigned) {
    $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
    $requirements = $service->requirements ?? '';

    $tasks = $taskRepo->getAllWithoutOwner(["id_service" => $assigned->id_service]);

    foreach ($tasks as $task) {
        $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
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
        "name" => $service ? $service->name : "Unknown Service",
        "id_service" => $assigned->id_service,
        "tasks" => $tasks,
        "quantity" => $assigned->quantity ?? 1,
        "requirements" => $requirements,
        "source" => "main_order",
        "suborder_id" => null
    ];
}

// Procesar subórdenes que tengan tareas asignadas
$subordersWithTasks = [];
foreach ($allSuborders as $suborder) {
    $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
    $hasAssignedTasks = false;
    $suborderServices = [];
    
    foreach ($suborderServicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $requirements = $service->requirements ?? '';
        
        $tasks = $taskRepo->getAllWithoutOwner(["id_service" => $assigned->id_service]);
        $assignedTasksCount = 0;
        
        foreach ($tasks as $task) {
            $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
                "id_suborder" => $suborder->id,
                "id_service" => $assigned->id_service,
                "task_description" => $task->task_name
            ]);
            
            if ($assignedTask && $assignedTask->id_user) {
                $hasAssignedTasks = true;
                $assignedTasksCount++;
            }
            
            $task->assigned_id_user = $assignedTask?->id_user;
            $task->assigned_user_name = $assignedTask?->id_user
                ? $userRepo->getOne(["id" => $assignedTask->id_user])->name
                : null;
            $task->id_task = $assignedTask?->id;
        }
        
        // Verificar tareas manuales de la suborden
        $suborderManualTasks = $teamTaskRepo->getAllWithoutOwner(["id_suborder" => $suborder->id, "id_service" => 0]);
        if (!empty($suborderManualTasks)) {
            foreach ($suborderManualTasks as $manualTask) {
                if ($manualTask->id_user) {
                    $hasAssignedTasks = true;
                }
            }
        }
        
        if ($assignedTasksCount > 0 || !empty($suborderManualTasks)) {
            $suborderServices[] = [
                "name" => $service ? $service->name : ($assigned->service_name ?? "Unknown Service"),
                "id_service" => $assigned->id_service,
                "tasks" => $tasks,
                "quantity" => $assigned->quantity ?? 1,
                "requirements" => $requirements
            ];
        }
    }
    
    // Solo incluir la suborden si tiene tareas asignadas
    if ($hasAssignedTasks && !empty($suborderServices)) {
        $suborderManualTasks = $teamTaskRepo->getAllWithoutOwner(["id_suborder" => $suborder->id, "id_service" => 0]);
        $subordersWithTasks[] = [
            "suborder" => $suborder,
            "services" => $suborderServices,
            "manualTasks" => $suborderManualTasks
        ];
    }
}

// Calcular total de servicios (principal + subórdenes con tareas)
$totalServices = count($services);
foreach ($subordersWithTasks as $suborderData) {
    $totalServices += count($suborderData["services"]);
}
$serviceIndex = 1;

// Servicios de la orden principal
foreach ($services as $assigned) {
    $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned["id_service"]);
    $noteEntry = $notesRepo->findByOrderAndService($orderId, $assigned["id_service"]);
    
    $hasManualEntry = $noteEntry?->has_manual_entry ?? 0;
    $customNote = $noteEntry?->notes ?? '';
    $tasks = $assigned["tasks"];
    $quantity = $assigned["quantity"];
    $requirements = $assigned["requirements"];

    $pdf->AddPage();
    $pdf->SetXY(165, 10);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(30, 10, "Service {$serviceIndex}/{$totalServices}", 0, 0, 'R');

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
        $pdf->Cell(150, 7, utf8_decode($row[1]), 1, 1);
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
        $pdf->Cell(0, 8, "STAFF ASSIGNMENTS", 1, 1, 'L', true);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(130, 8, 'Task', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'Assigned To', 1, 1, 'C', true);

        foreach ($tasks as $task) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(130, 8, utf8_decode($task->task_name), 1);
            $pdf->Cell(60, 8, utf8_decode($task->assigned_user_name ?? 'Not assigned'), 1, 1);
        }

        $pdf->Ln(3);
    }

    if ($hasManualEntry) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 8, "Additional Instructions", 0, 1);
        $pdf->MultiCell(190, 30, '', 1);
    }

    $serviceIndex++;
}

// Servicios de subórdenes con tareas asignadas
foreach ($subordersWithTasks as $suborderData) {
    $suborder = $suborderData["suborder"];
    $suborderServices = $suborderData["services"];
    
    foreach ($suborderServices as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned["id_service"]);
        $noteEntry = $notesRepo->findBySuborderAndService($suborder->id, $assigned["id_service"]);
        
        $hasManualEntry = $noteEntry?->has_manual_entry ?? 0;
        $customNote = $noteEntry?->notes ?? '';
        $tasks = $assigned["tasks"];
        $quantity = $assigned["quantity"];
        $requirements = $assigned["requirements"];

        $pdf->AddPage();
        $pdf->SetXY(165, 10);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(30, 10, "Sub-Order #{$suborder->id} - Service {$serviceIndex}/{$totalServices}", 0, 0, 'R');

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
            $pdf->Cell(150, 7, utf8_decode($row[1]), 1, 1);
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
            $pdf->Cell(0, 8, "STAFF ASSIGNMENTS", 1, 1, 'L', true);

            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(130, 8, 'Task', 1, 0, 'C', true);
            $pdf->Cell(60, 8, 'Assigned To', 1, 1, 'C', true);

            foreach ($tasks as $task) {
                $pdf->SetFont('Arial', '', 9);
                $pdf->Cell(130, 8, utf8_decode($task->task_name), 1);
                $pdf->Cell(60, 8, utf8_decode($task->assigned_user_name ?? 'Not assigned'), 1, 1);
            }

            $pdf->Ln(3);
        }

        if ($hasManualEntry) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 8, "Additional Instructions", 0, 1);
            $pdf->MultiCell(190, 30, '', 1);
        }

        $serviceIndex++;
    }
}

// TAREAS MANUALES DE ORDEN PRINCIPAL
if (!empty($manualTasks)) {
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, 'ADDITIONAL ASSIGNMENTS', 1, 1, 'L', true);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(200, 220, 255);
    $pdf->Cell(130, 8, 'Task Name', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Assigned To', 1, 1, 'C', true);

    foreach ($manualTasks as $task) {
        $userName = $task->id_user ? $userRepo->getOne(["id" => $task->id_user])->name : "Not assigned";
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(130, 8, utf8_decode($task->task_description), 1);
        $pdf->Cell(60, 8, utf8_decode($userName), 1, 1);
    }

    $pdf->Ln(10);
}

// TAREAS MANUALES DE SUBÓRDENES
foreach ($subordersWithTasks as $suborderData) {
    $suborder = $suborderData["suborder"];
    $suborderManualTasks = $suborderData["manualTasks"];
    
    if (!empty($suborderManualTasks)) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, "SUB-ORDER #{$suborder->id} - ADDITIONAL ASSIGNMENTS", 1, 1, 'L', true);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(200, 220, 255);
        $pdf->Cell(130, 8, 'Task Name', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'Assigned To', 1, 1, 'C', true);

        foreach ($suborderManualTasks as $task) {
            $userName = $task->id_user ? $userRepo->getOne(["id" => $task->id_user])->name : "Not assigned";
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(130, 8, utf8_decode($task->task_description), 1);
            $pdf->Cell(60, 8, utf8_decode($userName), 1, 1);
        }

        $pdf->Ln(10);
    }
}

if (!empty($order->notes)) {
    $pdf->AddPage();
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'CLIENT NOTES / OBSERVATIONS', 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Ln(2);
    $pdf->MultiCell(0, 8, utf8_decode($order->notes));
}

// SERVICE REQUIREMENTS FINAL
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 10, 'SERVICE REQUIREMENTS', 1, 1, 'L', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);

// Requirements de orden principal
foreach ($services as $svc) {
    $requirements = $svc["requirements"];

    if (!empty($requirements)) {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, '* ' . $svc["name"], 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, utf8_decode($requirements));
    }
}

// Requirements de subórdenes
foreach ($subordersWithTasks as $suborderData) {
    $suborder = $suborderData["suborder"];
    $suborderServices = $suborderData["services"];
    
    foreach ($suborderServices as $svc) {
        $requirements = $svc["requirements"];

        if (!empty($requirements)) {
            $pdf->Ln(4);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 8, '* Sub-Order #' . $suborder->id . ' - ' . $svc["name"], 0, 1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->MultiCell(0, 6, utf8_decode($requirements));
        }
    }
}

$pdf->Output();
