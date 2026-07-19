<?php

namespace App\Services;

use App\Repositories\OrdersRepository;
use App\Repositories\Connection;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class OrderInvoicePdfGenerator
{
    /**
     * Genera un PDF con estructura de Invoice/Order Summary (en inglés) y lo guarda.
     * Devuelve la ruta del archivo.
     */
    public static function generateAndSave(int $orderId): string
    {
        $ordersRepo = new OrdersRepository();
        $assignedRepo = new OrdersServicesAssignedRepository();
        $serviceRepo = new OrdersServiceRepository();
        $institutionRepo = new InstitutionProfileRepository();
        $userRepo = new UserRepository();

        $order = $ordersRepo->getOne(["id" => $orderId]);
        if (!$order) {
            throw new Exception("Order not found");
        }

        $client = $userRepo->getOne(["id" => $order->id_client]);
        $institution = $institutionRepo->getByOwner($order->id_owner);
        $institution = json_decode(json_encode($institution), true);

        $items = $assignedRepo->getAllBy(["id_order" => $order->id]);
        $subtotal = 0.0;

        $rows = '';
        foreach ($items as $it) {
            $service = $serviceRepo->getOne(["id" => $it->id_service]);
            if (!$service) { continue; }
            $unitPrice = ($it->is_variable === 'YES' && $it->variable_price !== null)
                ? (float)$it->variable_price
                : (float)$service->price;
            $lineSubtotal = $unitPrice * (float)$it->quantity;
            $subtotal += $lineSubtotal;

            $rows .= '<tr>
                <td>' . htmlspecialchars($service->name) . '</td>
                <td style="text-align:center;">' . (int)$it->quantity . '</td>
                <td style="text-align:right;">$' . number_format($unitPrice, 2) . '</td>
                <td style="text-align:right;">$' . number_format($lineSubtotal, 2) . '</td>
            </tr>';

            if (!empty($service->description) && $it->is_variable !== 'YES') {
                $rows .= '<tr><td colspan="4" style="font-size:11px;color:#66737d;">' . htmlspecialchars($service->description) . '</td></tr>';
            }
        }

        // Discount/Tax/Total
        $discountType = $order->discount_type ?? 'amount';
        $discountValue = (float)($order->discount_value ?? 0);
        $discountAmount = ($discountType === 'percentage')
            ? $subtotal * ($discountValue / 100)
            : $discountValue;
        $discountAmount = max(0.0, $discountAmount);
        $base = max($subtotal - $discountAmount, 0.0);
        $taxPercent = (float)($order->tax_percentage ?? 0);
        $taxAmount = $base * ($taxPercent / 100);
        $total = $base + $taxAmount;

        // Advances and payments (order + suborders) reduce the effective total
        $advancesTotal = 0.0;
        $paymentsTotal = 0.0;
        try {
            $db = new Connection();
            // Advances applied directly to the order
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
            $db->bind(":id", (int)$order->id);
            $db->execute();
            $row = $db->fetchAll()[0] ?? null;
            $advancesTotal += (float)($row->total_advanced ?? 0);

            // Advances applied to suborders of this order
            $db->query("SELECT COALESCE(SUM(oa.amount),0) AS total_advanced FROM orders_advances oa INNER JOIN orders_suborder s ON s.id = oa.id_suborder WHERE oa.is_suborder = 1 AND s.id_order = :id");
            $db->bind(":id", (int)$order->id);
            $db->execute();
            $row2 = $db->fetchAll()[0] ?? null;
            $advancesTotal += (float)($row2->total_advanced ?? 0);

            // Regular payments made for this order
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id");
            $db->bind(":id", (int)$order->id);
            $db->execute();
            $row3 = $db->fetchAll()[0] ?? null;
            $paymentsTotal = (float)($row3->total_paid ?? 0);

        } catch (\Throwable $e) {
            $advancesTotal = 0.0;
            $paymentsTotal = 0.0;
        }

        $totalPaid = $advancesTotal + $paymentsTotal;
        $effectiveTotal = max($total - $totalPaid, 0.0);

        // Deposit / Balance by split type
        $splitType = (int)($order->payment_split_type ?? 2); // 1: full, 2: split
        $p1 = (float)($order->payment_split_percent_1 ?? 50);
        $p2 = (float)($order->payment_split_percent_2 ?? 50);
        // Calculate deposit/balance against the effective total after advances
        $deposit = $splitType === 2 ? ($effectiveTotal * $p1 / 100) : $effectiveTotal;
        $balance = $effectiveTotal - $deposit;

        // Usar hora local del servidor sin conversiones de timezone
        $issuedAt = date('F j, Y');

        $orgName = $institution['name'] ?? 'VNV Events';
        $orgEmail = $institution['email'] ?? '';
        $orgPhone = $institution['phone'] ?? '';

        $html = '<html><head><meta charset="UTF-8" />
            <style>
                @page { margin: 36px 36px; }
                body{ font-family: Arial, sans-serif; color:#152026; font-size:12px; }
                .brand { color:#4a90e2; font-size:12px; }
                .brand b{ font-weight:700; }
                .header { border-top:4px solid #4a90e2; padding-top:10px; display:flex; justify-content:space-between; }
                .title { font-size:20px; margin: 16px 0 8px; }
                .muted { color:#66737d; font-size:11px; }
                .grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:8px; }
                .cell { background:#eef2f5; padding:10px; border-radius:6px; font-size:11px; }
                .cell b { display:block; color:#334048; margin-bottom:4px; font-size:10px; text-transform:uppercase; }
                table { width:100%; border-collapse:collapse; margin-top:12px; }
                th, td { border:1px solid #d6dde3; padding:8px; }
                th { background:#cfe3ff; text-align:center; }
                .totals td { border:none; }
                .right { text-align:right; }
                .hr { height:1px; background:#d6dde3; border:0; margin:12px 0; }
            </style></head><body>

            <div class="header">
                <div>
                    <div class="brand"><b>VNV</b> Events | Invoice</div>
                    <div class="muted">' . htmlspecialchars($orgName) . ' · ' . htmlspecialchars($orgEmail) . ' · ' . htmlspecialchars($orgPhone) . '</div>
                </div>
                <div class="muted">Issue Date: ' . $issuedAt . '</div>
            </div>

            <div class="title">Order Summary</div>
            <div class="grid">
                <div class="cell"><b>Client</b>' . htmlspecialchars(($client->name ?? '') . ' ' . ($client->lastname ?? '')) . '</div>
                <div class="cell"><b>Email</b>' . htmlspecialchars($client->email ?? '') . '</div>
                <div class="cell"><b>Phone</b>' . htmlspecialchars($client->phone ?? '') . '</div>
                <div class="cell"><b>Order ID</b>VNV-341' . $order->id . '</div>
            </div>
            <div class="grid" style="margin-top:8px;">
                <div class="cell"><b>Event Date</b>' . htmlspecialchars($order->event_date ?? '') . '</div>
                <div class="cell"><b>Address</b>' . htmlspecialchars($order->address ?? '') . '</div>
                <div class="cell"><b>Start</b>' . htmlspecialchars($order->start_time ?? '') . '</div>
                <div class="cell"><b>End</b>' . htmlspecialchars($order->end_time ?? '') . '</div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="text-align:left;">Service</th>
                        <th>Qty</th>
                        <th class="right">Unit Price</th>
                        <th class="right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $rows . '
                </tbody>
            </table>

            <table style="margin-top:8px;">
                <tr class="totals"><td style="width:70%;border:none;"></td><td style="width:30%;border:none;">
                    <table style="width:100%;">
                        <tr><td>Subtotal</td><td class="right">$' . number_format($subtotal, 2) . '</td></tr>
                        <tr><td>Discount (' . ($discountType === 'percentage' ? ($discountValue . '%') : ('$' . number_format($discountValue, 2))) . ')</td><td class="right">-$' . number_format($discountAmount, 2) . '</td></tr>
                        <tr><td>Tax (' . number_format($taxPercent, 2) . '%)</td><td class="right">$' . number_format($taxAmount, 2) . '</td></tr>
                        <tr><td><b>Total</b></td><td class="right"><b>$' . number_format($total, 2) . '</b></td></tr>';
        
        if ($advancesTotal > 0) {
            $html .= '<tr><td>Advances Applied</td><td class="right">- $' . number_format($advancesTotal, 2) . '</td></tr>';
        }
        
        if ($paymentsTotal > 0) {
            $html .= '<tr><td>Payments Made</td><td class="right">- $' . number_format($paymentsTotal, 2) . '</td></tr>';
        }
        
        $html .= '<tr><td><b>Total Due</b></td><td class="right"><b>$' . number_format($effectiveTotal, 2) . '</b></td></tr>
                    </table>
                </td></tr>
            </table>

            <div class="grid" style="margin-top:8px;">
                <div class="cell"><b>Deposit</b>$' . number_format($deposit, 2) . '</div>
                <div class="cell"><b>Balance</b>$' . number_format($balance, 2) . '</div>
            </div>

        </body></html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Arial');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $content = $dompdf->output();
        return FileUtils::saveFileFromContent($content, 'order_invoices', 'pdf');
    }
}


