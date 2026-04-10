<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesShoppingListRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\VnvPDF;

$user = LoginService::getSession();

$orderId = $_GET["id"] ?? null;
if (!$orderId) LocationUtils::redirectInternal("panel/orders/home");

$orderRepo = new OrdersRepository();
$assignedRepo = new OrdersServicesAssignedRepository();
$serviceRepo = new OrdersServiceRepository();
$shoppingRepo = new OrdersServicesShoppingListRepository();
$userRepo = new UserRepository();
$institutionRepo = new InstitutionProfileRepository();

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
$assignedServices = $assignedRepo->getAllBy(["id_order" => $orderId]);

$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 12, utf8_decode("Shopping List for Order VNV-341{$orderId}"), 0, 1, 'C');
$pdf->Ln(5);

// Detalles del evento
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

// Servicios de la orden principal
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(200, 220, 255);
$pdf->Cell(0, 8, "MAIN ORDER SERVICES", 1, 1, 'L', true);
$pdf->Ln(3);

$index = 1;

foreach ($assignedServices as $assigned) {
    $service = $serviceRepo->getOne(["id" => $assigned->id_service]);
    $items = $shoppingRepo->getByOrderAndService($orderId, $assigned->id_service);

    // Título del servicio con fondo negro
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, "PRODUCT / SERVICE {$index}: " . strtoupper($service->name), 1, 1, 'L', true);
    $index++;

    // Lista de compras
    if (!empty($items)) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(80, 8, 'Item', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(80, 8, 'Notes', 1, 1, 'C', true);

        foreach ($items as $item) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(80, 8, utf8_decode($item->item), 1);
            $pdf->Cell(30, 8, utf8_decode($item->quantity), 1);
            $pdf->Cell(80, 8, utf8_decode($item->notes), 1);
            $pdf->Ln();
        }
    } else {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 8, "No shopping items for this service.", 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }

    $pdf->Ln(6);
}

// Servicios de subórdenes
$suborders = $suborderRepo->getByOrder($orderId);
if (!empty($suborders)) {
    foreach ($suborders as $suborder) {
        $pdf->AddPage();
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(200, 255, 200);
        $pdf->Cell(0, 8, "SUB-ORDER #{$suborder->id} - SERVICES", 1, 1, 'L', true);
        $pdf->Ln(3);
        
        $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        $subIndex = 1;
        
        foreach ($suborderServicesAssigned as $assigned) {
            $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
            $items = $shoppingRepo->getBySuborderAndService($suborder->id, $assigned->id_service);

            // Título del servicio con fondo negro
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetFillColor(0, 0, 0);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, "PRODUCT / SERVICE {$subIndex}: " . strtoupper($service->name ?? $assigned->service_name ?? 'N/A'), 1, 1, 'L', true);
            $subIndex++;

            // Lista de compras
            if (!empty($items)) {
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->SetFillColor(230, 230, 230);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Cell(80, 8, 'Item', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Qty', 1, 0, 'C', true);
                $pdf->Cell(80, 8, 'Notes', 1, 1, 'C', true);

                foreach ($items as $item) {
                    $pdf->SetFont('Arial', '', 9);
                    $pdf->Cell(80, 8, utf8_decode($item->item), 1);
                    $pdf->Cell(30, 8, utf8_decode($item->quantity), 1);
                    $pdf->Cell(80, 8, utf8_decode($item->notes), 1);
                    $pdf->Ln();
                }
            } else {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->Cell(0, 8, "No shopping items for this service.", 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }

            $pdf->Ln(6);
        }
    }
}

$pdf->Output("I", "Shopping_List_Order_{$orderId}.pdf");
