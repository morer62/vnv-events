<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\ClientsUsersRepository;
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
$clientsRepo = new ClientsUsersRepository();

$user = LoginService::getSession();
$orderId = $_GET["id"] ?? null;

if (!$orderId) {
    MessageUtil::setMessage("Invalid order ID.");
    LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
}

// Obtener todos los IDs de dueños asociados si es cliente
$associatedOwnerIds = $clientsRepo->getOwnerIdsForClient($user->getId());

// Obtener orden sin ownership check
$order = $orderRepo->getByIdWithoutOwnershipCheck((int)$orderId);

// Verificación de acceso
$hasAccess = $order &&
    (
        $user->getLevel() != 5 || // Si no es cliente, tiene acceso directo
        $order["id_client"] == $user->getId() ||
        in_array($order["id_owner"], $associatedOwnerIds)
    );

if (!$order || !$hasAccess) {
    MessageUtil::setMessage("Order not found.");
    LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
}

$institution = $institutionRepo->getByOwner($order["id_owner"]);
$institution = json_decode(json_encode($institution), true);
$pdf = new VnvPDF($institution);

$client = $userRepo->getOne(["id" => $order["id_client"]]);
$assigned = $assignedRepo->getAllForClient($order["id"]);

$pdf->AddPage();

// ENCABEZADO
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Event Order Summary', 0, 1, 'C');
$pdf->Ln(2);

// INFORMACIÓN DEL CLIENTE Y EVENTO
$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(230, 230, 230);
$fields = [
    ['Order ID:', " VNV-341" . $order["id"]],
    ['Client:', $client->name . " " . $client->lastname],
    ['Email:', $client->email],
    ['Contact Number:', $client->phone],
    ['Event Date:', date("l, M j", strtotime($order["event_date"]))],
    ['Start Time:', date("g:i A", strtotime($order["start_time"]))],
    ['End Time:', date("g:i A", strtotime($order["end_time"]))],
    ['Address:', $order["address"]]
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
    $service = $serviceRepo->getByIdWithoutOwnershipCheck($item->id_service);

    if (!$service) {
        continue; // evita error si el servicio no existe
    }

    // Usar precio variable si existe, sino usar precio del servicio
    $unitPrice = ($item->is_variable === 'YES' && $item->variable_price !== null) 
        ? $item->variable_price 
        : $service->price;
    
    $subTotal = $item->quantity * $unitPrice;
    $subtotal += $subTotal;

    $pdf->Cell(70, 8, $service->name, 1);
    $pdf->Cell(30, 8, $item->quantity, 1, 0, 'C');
    $pdf->Cell(40, 8, '$' . number_format($unitPrice, 2), 1, 0, 'R');
    $pdf->Cell(50, 8, '$' . number_format($subTotal, 2), 1, 1, 'R');

    // Solo mostrar descripción si no es servicio de precio variable
    if (!empty($service->description) && $item->is_variable !== 'YES') {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(190, 6, "Note: " . $service->description, 0, 1);
        $pdf->SetFont('Arial', '', 10);
    }
}


// DESCUENTOS Y TOTALES
$discountValue = $order["discount_value"] ?? 0;
$discountType = $order["discount_type"] ?? 'amount';
$taxPercent = $order["tax_percentage"] ?? 0;

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

// NOTAS DEL CLIENTE
if (!empty($order["notes"])) {
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Client Notes / Observations', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, $order["notes"]);
    $pdf->Ln(5);

    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(50, 10, 'Client Signature:', 1, true);
    $pdf->Cell(140, 10, ' ', 1, 1, 'R');

    $pdf->Cell(50, 10, 'Date:', 1, true);
    $pdf->Cell(140, 10, ' ', 1, 1, 'R');
    $pdf->Ln(5);
}

// CONTRATO
if (!empty($order["id_contract"])) {
    $contract = $contractRepo->getOne(["id" => $order["id_contract"]]);
    if (!empty($contract->content)) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Contract Clauses', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $contract->content);
        $pdf->Ln(5);
    }
}

$pdf->Output();
