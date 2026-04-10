<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\VnvPDF;

$orderRepo = new OrdersRepository();
$contractRepo = new OrdersContractRepository();
$assignedRepo = new OrdersServicesAssignedRepository();
$serviceRepo = new OrdersServiceRepository();
$taskRepo = new OrdersServiceTasksRepository();
$userRepo = new UserRepository();
$institutionRepo = new InstitutionProfileRepository();

 
$user = LoginService::getSession();

$orderId = $_GET["id"] ?? null;
if (!$orderId) {
    MessageUtil::setMessage("Invalid order ID.");
    LocationUtils::redirectInternal("panel/planner-hub/team/orders/orders");
}

$order = $orderRepo->getOne([
    "id" => $orderId,
    "id_owner" => $user->getOwner()
]);



$institution = $institutionRepo->getByOwner($order->id_owner);
$institution = json_decode(json_encode($institution), true);
$pdf = new VnvPDF($institution);

$client = $userRepo->getOne(["id" => $order->id_client]);
$assigned = $assignedRepo->getAllBy(["id_order" => $orderId]);

$pdf->AddPage();

// ENCABEZADO
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Event Order Summary', 0, 1, 'C');
$pdf->Ln(2);

// INFORMACIÓN DEL CLIENTE Y EVENTO
$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(230, 230, 230);
$fields = [
    ['Order ID:', " VNV-341".$order->id],
    ['Client:', $client->name . " " . $client->lastname],
    ['Email:', $client->email ],
    ['Contact Number:', $client->phone ], 
    ['Address:', $order->address],
    ['Event Date:', date("l, M j - Y", strtotime($order->event_date))],
    ['Start Time:', date("g:i A", strtotime($order->start_time))],
    ['End Time:', date("g:i A", strtotime($order->end_time))], 
];

foreach ($fields as $row) {
    $pdf->Cell(50, 8, $row[0], 1, 0, 'L', true);
    $pdf->Cell(140, 8, $row[1], 1, 1);
}

$pdf->Ln(8);

// SERVICIOS INCLUIDOS
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Services Included', 0, 1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(200, 220, 255);
$pdf->Cell(70, 8, 'Service', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Qty', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'Unit Price', 1, 0, 'C', true);
$pdf->Cell(50, 8, 'Subtotal', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 10);
$subtotal = 0;

foreach ($assigned as $item) {
    $service = $serviceRepo->getOne(["id" => $item->id_service]);
    $subTotal = $item->quantity * $service->price;
    $subtotal += $subTotal;

    $pdf->Cell(70, 8, $service->name, 1);
    $pdf->Cell(30, 8, $item->quantity, 1, 0, 'C');
    $pdf->Cell(40, 8, '$' . number_format($service->price, 2), 1, 0, 'R');
    $pdf->Cell(50, 8, '$' . number_format($subTotal, 2), 1, 1, 'R');

    if (!empty($service->description)) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(190, 6, "Note: " . $service->description, 0, 1);
        $pdf->SetFont('Arial', '', 10);
    }
}

// DESCUENTO E IMPUESTOS
$discountValue = $order->discount_value ?? 0;
$discountType = $order->discount_type ?? 'amount';
$taxPercent = $order->tax_percentage ?? 0;

$discountAmount = $discountType === 'percentage'
    ? ($subtotal * ($discountValue / 100))
    : $discountValue;

$taxAmount = ($subtotal - $discountAmount) * ($taxPercent / 100);
$total = $subtotal - $discountAmount + $taxAmount;

// TOTALES
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(140, 8, 'Subtotal', 1);
$pdf->Cell(50, 8, '$' . number_format($subtotal, 2), 1, 1, 'R');

$pdf->Cell(140, 8, 'Discount (' . ($discountType === 'percentage' ? $discountValue . '%' : '$' . number_format($discountValue, 2)) . ')', 1);
$pdf->Cell(50, 8, '-$' . number_format($discountAmount, 2), 1, 1, 'R');

$pdf->Cell(140, 8, 'Tax (' . $taxPercent . '%)', 1);
$pdf->Cell(50, 8, '$' . number_format($taxAmount, 2), 1, 1, 'R');

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(140, 10, 'TOTAL', 1);
$pdf->Cell(50, 10, '$' . number_format($total, 2), 1, 1, 'R');

$pdf->Ln(10);

// FIRMA

$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 10, 'Client Signature:', 1, fill:true);
$pdf->Cell(140, 10, ' ', 1, 1, 'R');
 
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 10, 'Date:', 1, fill:true);
$pdf->Cell(140, 10, ' ', 1, 1, 'R');
$pdf->Ln(5);

$pdf->AddPage();

// NOTAS DEL CLIENTE
if (!empty($order->notes)) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Client Notes / Observations', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, $order->notes);
    $pdf->Ln(5);
}

$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 10, 'Client Signature:', 1, fill:true);
$pdf->Cell(140, 10, ' ', 1, 1, 'R');
 
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 10, 'Date:', 1, fill:true);
$pdf->Cell(140, 10, ' ', 1, 1, 'R');
$pdf->Ln(5);
 


$pdf->Output();
