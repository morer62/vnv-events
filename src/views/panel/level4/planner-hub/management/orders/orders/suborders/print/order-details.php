<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\VnvPDF;

$orderRepo = new OrdersRepository();
$contractRepo = new OrdersContractRepository();
$assignedRepo = new OrderSuborderServicesAssignedRepository();
$serviceRepo = new OrdersServiceRepository();
$taskRepo = new OrdersServiceTasksRepository();
$userRepo = new UserRepository();
$institutionRepo = new InstitutionProfileRepository();

$user = LoginService::getSession();

$suborderId = $_GET["id"] ?? null;
if (!$suborderId) {
    MessageUtil::setMessage("Invalid sub-order ID.");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
}

$suborderRepo = new \App\Repositories\OrdersSuborderRepository();
$suborder = $suborderRepo->getOne(["id" => $suborderId]);

if (!$suborder) {
    MessageUtil::setMessage("Sub-order not found.");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
}

if ($user->getLevel() === 4) {
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    if ($currentInstitutionId) {
        $institutionRepo_temp = new \App\Repositories\InstitutionProfileRepository();
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
    $order = $orderRepo->getOne([
        "id" => $suborder->id_order,
        "id_owner" => $user->getOwner()
    ]);
}

if (!$order) {
    MessageUtil::setMessage("Order not found.");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
}

$institution = $institutionRepo->getByOwner($order->id_owner);

if ($institution && !empty($institution->logo_path)) {
    if (strpos($institution->logo_path, 'res.cloudinary.com') !== false && 
        strpos($institution->logo_path, 'http') === false) {
        $institution->logo_path = 'https://' . ltrim($institution->logo_path, '/');
    }
}

$pdf = new VnvPDF($institution ? (array)$institution : []);
$client = $userRepo->getOne(["id" => $order->id_client]);
$assigned = $assignedRepo->getAllBy(["id_suborder" => $suborderId]);

$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Order Summary', 0, 1, 'C');
$pdf->Ln(2);

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
    $unitPrice = ($item->is_variable === 'YES' && $item->variable_price !== null) 
        ? $item->variable_price 
        : $service->price;
    $subTotal = $item->quantity * $unitPrice;
    $subtotal += $subTotal;

    $pdf->Cell(70, 8, $service->name, 1);
    $pdf->Cell(30, 8, $item->quantity, 1, 0, 'C');
    $pdf->Cell(40, 8, '$' . number_format($unitPrice, 2), 1, 0, 'R');
    $pdf->Cell(50, 8, '$' . number_format($subTotal, 2), 1, 1, 'R');

    if (!empty($service->description)) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(190, 6, "Note: " . $service->description, 0, 1);
        $pdf->SetFont('Arial', '', 10);
    }
}

$discountValue = $suborder->discount_value ?? 0;
$discountType = $suborder->discount_type ?? 'amount';
$taxPercent = $suborder->tax_percertance ?? 0;

$discountAmount = $discountType === 'percentage'
    ? ($subtotal * ($discountValue / 100))
    : $discountValue;

$taxAmount = ($subtotal - $discountAmount) * ($taxPercent / 100);
$total = $subtotal - $discountAmount + $taxAmount;

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

$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 10, 'Client Signature:', 1, fill:true);
$pdf->Cell(140, 10, ' ', 1, 1, 'R');
 
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(50, 10, 'Date:', 1, fill:true);
$pdf->Cell(140, 10, ' ', 1, 1, 'R');
$pdf->Ln(5);

$pdf->AddPage();

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
