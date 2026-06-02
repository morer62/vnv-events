<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Repositories\StoreOrderWorkflowRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrdersRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

function downloadStoreOrderLabelPdf(int $orderId): void
{
    $ordersRepo = new StoreOrdersRepository();
    $itemsRepo = new StoreOrderItemsRepository();
    $order = $ordersRepo->getById($orderId);
    if (!$order) {
        MessageUtil::setMessage('Order not found for label.');
        LocationUtils::reload();
    }
    $items = $itemsRepo->getByOrder($orderId);

    $pdf = new \TCPDF('P', 'mm', [80, 140], true, 'UTF-8', false);
    $pdf->SetCreator('Avomeal');
    $pdf->SetAuthor('Avomeal');
    $pdf->SetTitle('Store Label #' . $orderId);
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    $logoPath = LocationUtils::getRootLocation() . '/public/assets/images/planner-hub-logo-negative.png';
    if (file_exists($logoPath)) {
        $logoW = 16;
        $logoX = ($pdf->getPageWidth() - $logoW) / 2;
        $pdf->Image($logoPath, $logoX, 4, $logoW, 16, 'PNG');
        $pdf->SetY(22);
    } else {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 5, 'Avomeal', 0, 1, 'C');
    }

    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 4, 'Delivery label', 0, 1, 'L');
    $pdf->Ln(1);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(24, 5, 'ORDER', 1, 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, '#' . $orderId, 1, 1, 'L');

    $receiver = trim(($order->guest_name ?? '') !== '' ? (string)$order->guest_name : '—');
    $shippingParts = array_filter([
        trim((string)($order->shipping_address_1 ?? '')),
        trim((string)($order->shipping_city ?? '')),
        trim((string)(
            trim((string)($order->shipping_state ?? '')) .
            (((string)($order->shipping_zip ?? '') !== '') ? (' ' . trim((string)$order->shipping_zip)) : '')
        ))
    ], function ($v) {
        return $v !== '';
    });
    $shippingAddress = $shippingParts ? implode(', ', $shippingParts) : '';
    if ($shippingAddress === '') {
        $notes = (string)($order->notes ?? '');
        if ($notes !== '' && preg_match('/Shipping:\s*(.+)$/i', $notes, $m)) {
            $shippingAddress = trim((string)$m[1]);
        }
    }
    if ($shippingAddress === '') {
        $shippingAddress = trim(($order->city ?? '') !== '' ? (string)$order->city : '—');
    }
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(24, 5, 'RECEIVER', 1, 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, $receiver, 1, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(24, 7, 'SHIPPING', 1, 0, 'L');
    $pdf->SetFont('helvetica', '', 8.3);
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $w = $pdf->getPageWidth() - $pdf->getMargins()['right'] - $x;
    $h = max(7.0, $pdf->getStringHeight($w - 1, $shippingAddress));
    $pdf->MultiCell($w, $h, $shippingAddress, 1, 'L', false, 1, $x, $y, true, 0, false, true, $h, 'M');
    $pdf->Ln(1);

    $pdf->SetFont('helvetica', 'B', 8);
    $mealW = 38;
    $qtyW = 8;
    $unitW = 12;
    $lineW = 14;
    $pdf->Cell($mealW, 5, 'Meal', 1, 0, 'L');
    $pdf->Cell($qtyW, 5, 'Qty', 1, 0, 'C');
    $pdf->Cell($unitW, 5, 'Unit', 1, 0, 'R');
    $pdf->Cell($lineW, 5, 'Line', 1, 1, 'R');

    usort($items, function ($a, $b) {
        $nameA = strtolower((string)($a->product_name_snapshot ?? ''));
        $nameB = strtolower((string)($b->product_name_snapshot ?? ''));
        return $nameA <=> $nameB;
    });

    $pdf->SetFont('helvetica', '', 7.2);
    foreach ($items as $item) {
        $name = (string)($item->product_name_snapshot ?? 'Meal');
        $qty = (int)($item->quantity ?? 0);
        $unit = '$' . number_format((float)($item->unit_price ?? 0), 2);
        $line = '$' . number_format((float)($item->line_total ?? 0), 2);
        $nameText = trim($name) !== '' ? $name : 'Meal';

        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        $rowH = max(5.0, $pdf->getStringHeight($mealW - 1, $nameText));

        $pdf->MultiCell($mealW, $rowH, $nameText, 1, 'L', false, 0, $startX, $startY, true, 0, false, true, $rowH, 'M');
        $pdf->SetXY($startX + $mealW, $startY);
        $pdf->Cell($qtyW, $rowH, (string)$qty, 1, 0, 'C');
        $pdf->Cell($unitW, $rowH, $unit, 1, 0, 'R');
        $pdf->Cell($lineW, $rowH, $line, 1, 0, 'R');
        $pdf->SetXY($startX, $startY + $rowH);
    }
    if (!$items) {
        $pdf->Cell(0, 5, 'No meal details', 1, 1, 'C');
    }

    $pdf->Ln(1);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Total paid: $' . number_format((float)($order->total ?? 0), 2), 0, 1, 'R');
    $pdf->Ln(1);

    $siteUrl = 'https://vnvevents.com/store/';
    $pdf->SetFont('helvetica', '', 8);
    $pdf->MultiCell(42, 8, "Thank you for choosing Avomeal.\nEnjoy your meals and have a great day.", 0, 'L', false, 0, '', '', true);
    $style = ['border' => 0, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false];
    $pdf->write2DBarcode($siteUrl, 'QRCODE,H', 55, $pdf->GetY() - 1, 18, 18, $style, 'N');
    $pdf->SetXY(50, $pdf->GetY() + 18);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->Cell(26, 4, 'vnvevents.com', 0, 1, 'C');

    $pdf->Output('avomeal-label-order-' . $orderId . '.pdf', 'D');
}

$router->get(function () {
    $user = LoginService::getSession();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    $storeUserRolesRepo = new StoreUserRolesRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $itemsRepo = new StoreOrderItemsRepository();
    $deliveryUsers = [];

    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitution = null;
    $storeTeamRole = 'general';
    $ownerId = 0;

    if ($currentInstitutionId) {
        $currentInstitution = $institutionRepo->getById($currentInstitutionId);
    }

    if (!$currentInstitution) {
        $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
        if ($primaryInstitution) {
            $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
            $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
        }
    }

    if ($currentInstitution) {
        $ownerId = (int)($currentInstitution->id_owner ?? 0);
        if ($ownerId > 0) {
            $storeTeamRole = $storeUserRolesRepo->getRoleValueByOwnerAndUser($ownerId, (int)$user->getId()) ?: 'general';
        }
    }

    $orders = [];
    $preparationTotals = [];
    if ($ownerId > 0) {
        $orders = $workflowRepo->getKitchenOrdersQueue($ownerId);
        $deliveryUsers = $storeUserRolesRepo->getUsersByOwnerAndRole($ownerId, 'delivery');
        $deliveryUsersById = [];
        foreach ($deliveryUsers as $du) {
            $deliveryUsersById[(int)$du->id_user] = trim(($du->name ?? '') . ' ' . ($du->lastname ?? ''));
        }
        foreach ($orders as &$order) {
            $order->items = $itemsRepo->getByOrder((int)$order->id);
            $order->items_json = json_encode($order->items);
            $deliveryUserId = (int)($order->delivery_user_id ?? 0);
            $order->delivery_assignee_name = $deliveryUserId > 0 ? ($deliveryUsersById[$deliveryUserId] ?? '') : '';
        }
        unset($order);

        $orderIds = array_map(fn($o) => (int)$o->id, $orders);
        $preparationTotals = $itemsRepo->getPreparationTotalsByOrders($orderIds);
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'user' => $user,
        'currentInstitution' => $currentInstitution,
        'storeTeamRole' => $storeTeamRole,
        'orders' => $orders,
        'preparationTotals' => $preparationTotals,
        'deliveryUsers' => $deliveryUsers,
    ]);
});

$router->post(function () {
    $workflowRepo = new StoreOrderWorkflowRepository();
    $ordersRepo = new StoreOrdersRepository();

    $action = $_POST['action'] ?? '';
    $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    if ($orderId <= 0) {
        MessageUtil::setMessage('Invalid order.');
        LocationUtils::reload();
    }

    if ($action === 'download_label_pdf') {
        downloadStoreOrderLabelPdf($orderId);
        return '';
    }

    if ($action === 'mark_sending') {
        $deliveryUserId = isset($_POST['delivery_user_id']) && $_POST['delivery_user_id'] !== ''
            ? (int)$_POST['delivery_user_id']
            : 0;
        if ($deliveryUserId <= 0) {
            MessageUtil::setMessage('Select a delivery user before sending.');
            LocationUtils::reload();
        }
        $order = $ordersRepo->getById($orderId);
        if (!$order) {
            MessageUtil::setMessage('Order not found.');
            LocationUtils::reload();
        }
        $ownerId = (int)($order->id_owner ?? 0);
        if ($ownerId <= 0) {
            MessageUtil::setMessage('Invalid order owner.');
            LocationUtils::reload();
        }
        $existing = $workflowRepo->getByOrder($orderId);
        $kitchenUserId = $existing ? (int)($existing->kitchen_user_id ?? 0) : 0;
        if ($kitchenUserId <= 0) {
            $session = LoginService::getSession();
            $kitchenUserId = (int)$session->getId();
        }
        $okAssign = $workflowRepo->upsertAssignments($ownerId, $orderId, $kitchenUserId, $deliveryUserId);
        $okMark = $workflowRepo->markSending($orderId);
        $okStatus = $ordersRepo->updateStatus($orderId, StoreOrdersRepository::STATUS_SENDING);
        MessageUtil::setMessage(($okAssign && $okMark && $okStatus) ? 'Order marked as sending.' : 'Could not update order status.');
        LocationUtils::reload();
    }

    if ($action === 'assign_delivery') {
        $deliveryUserId = isset($_POST['delivery_user_id']) && $_POST['delivery_user_id'] !== ''
            ? (int)$_POST['delivery_user_id']
            : null;
        $order = $ordersRepo->getById($orderId);
        if (!$order) {
            MessageUtil::setMessage('Order not found.');
            LocationUtils::reload();
        }
        $ownerId = (int)($order->id_owner ?? 0);
        if ($ownerId <= 0) {
            MessageUtil::setMessage('Invalid order owner.');
            LocationUtils::reload();
        }
        $existing = $workflowRepo->getByOrder($orderId);
        $kitchenUserId = $existing ? (int)($existing->kitchen_user_id ?? 0) : 0;
        $ok = $workflowRepo->upsertAssignments($ownerId, $orderId, $kitchenUserId > 0 ? $kitchenUserId : null, $deliveryUserId);
        MessageUtil::setMessage($ok ? 'Delivery assignment updated.' : 'Failed to update delivery assignment.');
        LocationUtils::reload();
    }

    if ($action === 'mark_received') {
        $okStatus = $ordersRepo->updateStatus($orderId, StoreOrdersRepository::STATUS_DELIVERED);
        MessageUtil::setMessage($okStatus ? 'Order marked as received.' : 'Could not update order status.');
        LocationUtils::reload();
    }

    MessageUtil::setMessage('Invalid action.');
    LocationUtils::reload();
});

$router->run();
