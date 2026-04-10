<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesShoppingListRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\VnvPDF;

$user = LoginService::getSession();

$suborderId = $_GET["id"] ?? null;
if (!$suborderId) LocationUtils::redirectInternal("panel/orders/home");

$orderRepo = new OrdersRepository();
$assignedRepo = new OrderSuborderServicesAssignedRepository();
$serviceRepo = new OrdersServiceRepository();
$shoppingRepo = new OrdersServicesShoppingListRepository();
$userRepo = new UserRepository();
$institutionRepo = new InstitutionProfileRepository();

$suborderRepo = new \App\Repositories\OrdersSuborderRepository();
$suborder = $suborderRepo->getOne(["id" => $suborderId]);

if ($user->getLevel() === 4) {
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    if ($currentInstitutionId) {
        $institutionRepo_temp = new InstitutionProfileRepository();
        $institution_temp = $institutionRepo_temp->getById($currentInstitutionId);
        if ($institution_temp && $institution_temp->id_owner) {
            $order = $orderRepo->getOneByIdAndOwner($suborder->id_order, $institution_temp->id_owner);
        } else {
            $order = null;
        }
    } else {
        $order = null;
    }
} else {
    $order = $orderRepo->getOne(["id" => $suborder->id_order, "id_owner" => $user->getOwner()]);
}

if (!$order) {
    MessageUtil::setMessage("Order not found.");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
}

$institution = $institutionRepo->getByOwner($order->id_owner);
$institution = json_decode(json_encode($institution), true);
$pdf = new VnvPDF($institution);

$client = $userRepo->getOne(["id" => $order->id_client]);
$assignedServices = $assignedRepo->getAllBy(["id_suborder" => $suborderId]);

$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 12, utf8_decode("Shopping List for Order VNV-341{$order->id}"), 0, 1, 'C');
$pdf->Ln(5);

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

$index = 1;

foreach ($assignedServices as $assigned) {
    $service = $serviceRepo->getOne(["id" => $assigned->id_service]);
    $items = $shoppingRepo->getBySuborderAndService($suborderId, $assigned->id_service);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, "PRODUCT / SERVICE {$index}: " . strtoupper($service->name), 1, 1, 'L', true);
    $index++;

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

$pdf->Output("I", "Shopping_List_Order_{$order->id}.pdf");
