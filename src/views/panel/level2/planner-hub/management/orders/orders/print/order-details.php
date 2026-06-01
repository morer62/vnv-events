<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\TipsRepository;
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
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
}

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
    $order = $orderRepo->getOne([
        "id" => $orderId,
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

// Crear PDF
$pdf = new VnvPDF($institution ? (array)$institution : []);
$pdf = new VnvPDF($institution ? (array)$institution : []);
$client = $userRepo->getOne(["id" => $order->id_client]);
$assigned = $assignedRepo->getAllBy(["id_order" => $orderId]);

$pdf = new VnvPDF($institution ? (array)$institution : []);


$pdf->SetTitle('Order Summary - VNV-' . $order->id);
$pdf->SetAuthor('VNV Events');
$pdf->SetCreator('VNV System');
$pdf->AddPage();

// ENCABEZADO
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Event Order Summary', 0, 1, 'C');
$pdf->Ln(2);

// INFORMACIÓN DEL CLIENTE Y EVENTO
$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(230, 230, 230);
$fields = [
    ['Order ID:', "VNV-" . $order->id],
    ['Client:', $client->name . " " . $client->lastname],
    ['Email:', $client->email],
    ['Contact Number:', $client->phone], 
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
    $service = $serviceRepo->getByIdWithoutOwnershipCheck($item->id_service);
    
    // Usar el precio histórico almacenado (unit_price) si existe, sino usar el precio actual del servicio
    if (isset($item->unit_price) && $item->unit_price > 0) {
        $unitPrice = $item->unit_price;
    } else {
        // Fallback para órdenes antiguas que no tienen unit_price
        $unitPrice = ($item->is_variable === 'YES' && $item->variable_price !== null) 
            ? $item->variable_price 
            : $service->price;
    }
    
    $subTotal = $item->quantity * $unitPrice;
    $subtotal += $subTotal;

    $pdf->Cell(70, 8, $service->name, 1);
    $pdf->Cell(30, 8, $item->quantity, 1, 0, 'C');
    $pdf->Cell(40, 8, '$' . number_format($unitPrice, 2), 1, 0, 'R');
    $pdf->Cell(50, 8, '$' . number_format($subTotal, 2), 1, 1, 'R');

    // Usar la descripción histórica guardada si existe, sino usar la del servicio actual
    $description = null;
    if (isset($item->description) && $item->description) {
        $description = $item->description; // Descripción histórica
    } else {
        $description = $service->description ?? null; // Fallback para órdenes antiguas
    }
    
    if (!empty($description)) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(190, 6, "Note: " . utf8_decode($description), 0, 1);
        $pdf->SetFont('Arial', '', 10);
    }
}

$pdf->Ln(10);

// DESCUENTO E IMPUESTOS
$discountValue = $order->discount_value ?? 0;
$discountType = $order->discount_type ?? 'amount';
$taxPercent = $order->tax_percentage ?? 0;

$discountAmount = $discountType === 'percentage'
    ? ($subtotal * ($discountValue / 100))
    : $discountValue;

$base = $subtotal - $discountAmount;
$taxAmount = $base * ($taxPercent / 100);

$tipAmount = 0;
$tipPercentage = 0;
if (!empty($order->id_tip)) {
    $tipsRepo = new TipsRepository();
    $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
    if ($tip && $tip->is_active == 1) {
        $tipAmount = $base * ($tip->percentage / 100);
        $tipPercentage = $tip->percentage;
    }
}

$total = $base + $taxAmount + $tipAmount;

// TOTALES
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(140, 8, 'Subtotal', 1);
$pdf->Cell(50, 8, '$' . number_format($subtotal, 2), 1, 1, 'R');

if ($discountAmount > 0) {
    if ($discountType === 'percentage') {
        // Calcular el porcentaje original basado en el subtotal y el monto del descuento
        $originalPercentage = ($subtotal > 0) ? round(($discountAmount / $subtotal) * 100, 2) : 0;
        $discountLabel = 'Discount (' . $originalPercentage . '%)';
    } else {
        $discountLabel = 'Discount';
    }
    $pdf->Cell(140, 8, $discountLabel, 1);
    $pdf->Cell(50, 8, '-$' . number_format($discountAmount, 2), 1, 1, 'R');
}

$pdf->Cell(140, 8, 'Tax & Processing Fee (' . $taxPercent . '%)', 1);
$pdf->Cell(50, 8, '$' . number_format($taxAmount, 2), 1, 1, 'R');

if ($tipAmount > 0) {
    $pdf->Cell(140, 8, 'Tip (' . $tipPercentage . '%)', 1);
    $pdf->Cell(50, 8, '$' . number_format($tipAmount, 2), 1, 1, 'R');
}

// CALCULAR ABONOS Y PAGOS REALIZADOS
$advancesTotal = 0.0;
$paymentsTotal = 0.0;
try {
    $db = new \App\Repositories\Connection();
    
    // Abonos aplicados directamente a la orden
    $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
    $db->bind(":id", $order->id);
    $db->execute();
    $row = $db->fetchAll()[0] ?? null;
    $advancesTotal += (float)($row->total_advanced ?? 0);

    // Abonos aplicados a subórdenes de esta orden
    $db->query("SELECT COALESCE(SUM(oa.amount),0) AS total_advanced FROM orders_advances oa INNER JOIN orders_suborder s ON s.id = oa.id_suborder WHERE oa.is_suborder = 1 AND s.id_order = :id");
    $db->bind(":id", $order->id);
    $db->execute();
    $row2 = $db->fetchAll()[0] ?? null;
    $advancesTotal += (float)($row2->total_advanced ?? 0);

    // Pagos regulares realizados
    $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id");
    $db->bind(":id", $order->id);
    $db->execute();
    $row3 = $db->fetchAll()[0] ?? null;
    $paymentsTotal = (float)($row3->total_paid ?? 0);

} catch (\Throwable $e) {
    $advancesTotal = 0.0;
    $paymentsTotal = 0.0;
}

$totalPaid = $advancesTotal + $paymentsTotal;
$effectiveTotal = max($total - $totalPaid, 0.0);

if ($advancesTotal > 0) {
    $pdf->Cell(140, 8, 'Advances Applied', 1);
    $pdf->Cell(50, 8, '-$' . number_format($advancesTotal, 2), 1, 1, 'R');
}

if ($paymentsTotal > 0) {
    $pdf->Cell(140, 8, 'Payments Made', 1);
    $pdf->Cell(50, 8, '-$' . number_format($paymentsTotal, 2), 1, 1, 'R');
}

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(140, 10, 'TOTAL DUE', 1);
$pdf->Cell(50, 10, '$' . number_format($effectiveTotal, 2), 1, 1, 'R');

$pdf->Ln(10);

// Mostrar resumen de pagos realizados
if ($totalPaid > 0) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Payment Summary', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    if ($advancesTotal > 0) {
        $pdf->Cell(0, 6, 'Advances Applied: $' . number_format($advancesTotal, 2), 0, 1);
    }
    if ($paymentsTotal > 0) {
        $pdf->Cell(0, 6, 'Payments Made: $' . number_format($paymentsTotal, 2), 0, 1);
    }
    $pdf->Cell(0, 6, 'Total Paid: $' . number_format($totalPaid, 2), 0, 1);
    $pdf->Ln(5);
    
    // Obtener detalles de los pagos para mostrar información de tarjeta
    $paymentsRepo = new OrdersPaymentsRepository();
    $allPayments = $paymentsRepo->getAllBy(["id_order" => $orderId]);
    
    // Filtrar solo pagos de la orden principal (no subórdenes)
    $mainPayments = array_filter($allPayments, function($p) {
        return empty($p->id_suborder) || $p->id_suborder == 0;
    });
    
    // Buscar el último pago con detalles de tarjeta
    $paymentWithCard = null;
    foreach (array_reverse($mainPayments) as $payment) {
        if (isset($payment->card_brand) || isset($payment->card_last4)) {
            $paymentWithCard = $payment;
            break;
        }
    }
    
    // Mostrar Payment Method si hay datos de tarjeta
    if ($paymentWithCard) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Payment Method', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        
        $cardType = isset($paymentWithCard->card_brand) 
            ? ucfirst($paymentWithCard->card_brand) . ' Credit Card' 
            : 'Credit Card';
        $pdf->Cell(0, 6, 'Type: ' . $cardType, 0, 1);
        
        if (isset($paymentWithCard->card_last4)) {
            $cardNumber = 'XXXX-XXXX-XXXX-' . $paymentWithCard->card_last4;
            $pdf->Cell(0, 6, 'Number: ' . $cardNumber, 0, 1);
        }
        
        if (isset($paymentWithCard->card_exp_month) && isset($paymentWithCard->card_exp_year)) {
            $expMonth = str_pad($paymentWithCard->card_exp_month, 2, '0', STR_PAD_LEFT);
            $expYear = $paymentWithCard->card_exp_year;
            $pdf->Cell(0, 6, 'Expires on: ' . $expMonth . '/' . $expYear, 0, 1);
        }
        
        $pdf->Ln(5);
    }
}

// OBTENER Y MOSTRAR SUBÓRDENES
$suborderRepo = new OrdersSuborderRepository();
$suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
$paymentsRepo = new OrdersPaymentsRepository();

$suborders = $suborderRepo->getByOrder($orderId);

if (!empty($suborders) && count($suborders) > 0) {
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Sub-Orders Details', 0, 1, 'C');
    $pdf->Ln(5);
    
    foreach ($suborders as $index => $suborder) {
        // Obtener servicios de la suborden
        $suborderServices = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        
        // Calcular totales de la suborden
        $suborderSubtotal = 0.0;
        foreach ($suborderServices as $s) {
            $suborderSubtotal += (float)$s->quantity * (float)$s->actual_price;
        }
        
        $discountSub = (float)($suborder->discount_value ?? 0);
        $baseSub = max($suborderSubtotal - $discountSub, 0);
        $taxRateSub = (float)($suborder->tax_percertance ?? 0);
        $taxSub = $baseSub * ($taxRateSub / 100);
        $totalSub = round($baseSub + $taxSub, 2);
        
        // Calcular pagos y abonos de la suborden
        $subPayments = $paymentsRepo->getAllBy(["id_suborder" => $suborder->id]);
        $subPaid = 0.0;
        foreach ($subPayments as $p) {
            $paid = isset($p->amount) ? (float)$p->amount : 0.0;
            $refunded = isset($p->refunded_amount) ? (float)$p->refunded_amount : 0.0;
            $subPaid += max(0.0, $paid - $refunded);
        }
        
        $db = new \App\Repositories\Connection();
        $sumAdvancesSub = 0.0;
        try {
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
            $db->bind(":id", $suborder->id);
            $db->execute();
            $row = $db->fetchAll()[0] ?? null;
            $sumAdvancesSub = (float)($row->total_advanced ?? 0);
        } catch (\Throwable $e) {
            $sumAdvancesSub = 0.0;
        }
        
        $totalPaidSub = $subPaid + $sumAdvancesSub;
        $remainingSub = max($totalSub - $totalPaidSub, 0);
        
        // Encabezado de la suborden
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, 'Sub-Order #' . $suborder->id, 1, 1, 'L', true);
        $pdf->Ln(2);
        
        // Servicios de la suborden
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(200, 220, 255);
        $pdf->Cell(70, 8, 'Service', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Unit Price', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Subtotal', 1, 1, 'C', true);
        
        $pdf->SetFont('Arial', '', 10);
        foreach ($suborderServices as $service) {
            $unitPrice = (float)$service->actual_price;
            $serviceSubtotal = (float)$service->quantity * $unitPrice;
            
            $pdf->Cell(70, 8, utf8_decode($service->service_name ?? 'N/A'), 1);
            $pdf->Cell(30, 8, $service->quantity, 1, 0, 'C');
            $pdf->Cell(40, 8, '$' . number_format($unitPrice, 2), 1, 0, 'R');
            $pdf->Cell(50, 8, '$' . number_format($serviceSubtotal, 2), 1, 1, 'R');
            
            // Mostrar descripción histórica si existe
            if (!empty($service->service_description)) {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(190, 6, "Note: " . utf8_decode($service->service_description), 0, 1);
                $pdf->SetFont('Arial', '', 10);
            }
        }
        
        $pdf->Ln(2);
        
        // Totales de la suborden
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(140, 8, 'Subtotal', 1);
        $pdf->Cell(50, 8, '$' . number_format($suborderSubtotal, 2), 1, 1, 'R');
        
        if ($discountSub > 0) {
            $pdf->Cell(140, 8, 'Discount', 1);
            $pdf->Cell(50, 8, '-$' . number_format($discountSub, 2), 1, 1, 'R');
        }
        
        $pdf->Cell(140, 8, 'Tax & Processing Fee (' . $taxRateSub . '%)', 1);
        $pdf->Cell(50, 8, '$' . number_format($taxSub, 2), 1, 1, 'R');
        
        if ($sumAdvancesSub > 0) {
            $pdf->Cell(140, 8, 'Advances Applied', 1);
            $pdf->Cell(50, 8, '-$' . number_format($sumAdvancesSub, 2), 1, 1, 'R');
        }
        
        if ($subPaid > 0) {
            $pdf->Cell(140, 8, 'Payments Made', 1);
            $pdf->Cell(50, 8, '-$' . number_format($subPaid, 2), 1, 1, 'R');
        }
        
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(140, 10, 'TOTAL DUE', 1);
        $pdf->Cell(50, 10, '$' . number_format($remainingSub, 2), 1, 1, 'R');
        
        // Estado de la suborden
        $pdf->Ln(3);
        $pdf->SetFont('Arial', '', 9);
        $statusText = '';
        switch ($suborder->status_workflow ?? 'INVOICE_READY') {
            case 'INVOICE_DRAFT':
                $statusText = 'Signature Pending';
                break;
            case 'INVOICE_READY':
                $statusText = 'Signed – Payment Pending';
                break;
            case 'INVOICE_PARTIAL':
                $statusText = 'First Payment Completed';
                break;
            case 'INVOICE_PAID':
                $statusText = 'Fully Paid';
                break;
            default:
                $statusText = $suborder->status_workflow ?? 'N/A';
        }
        $pdf->Cell(0, 6, 'Status: ' . $statusText, 0, 1);
        
        // Separador entre subórdenes
        if ($index < count($suborders) - 1) {
            $pdf->Ln(5);
            $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
            $pdf->Ln(5);
        }
    }
}

$pdf->AddPage();

// NOTAS DEL CLIENTE
if (!empty($order->notes)) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Client Notes / Observations', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, $order->notes);
    $pdf->Ln(5);
}

// Generar el PDF
$pdf->Output('I', 'Order_VNV-' . $order->id . '.pdf');
