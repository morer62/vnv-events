<?php

namespace App\Services;

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class PaymentReceiptPdfGenerator
{
    public static function generateAndSave(int $orderId, ?int $suborderId, float $amountPaid, string $paymentMethodLabel = 'Stripe', string $paymentConcept = ''): string
    {
        $orderRepo = new OrdersRepository();
        $suborderRepo = new OrdersSuborderRepository();
        $userRepo = new UserRepository();
        $institutionRepo = new InstitutionProfileRepository();
        $paymentsRepo = new OrdersPaymentsRepository();

        $order = $orderRepo->getOne(["id" => $orderId]);
        if (!$order) {
            throw new Exception("Order not found");
        }
        $client = $userRepo->getOne(["id" => $order->id_client]);
        $institution = $institutionRepo->getByOwner($order->id_owner);
        $institution = json_decode(json_encode($institution), true);

     
        // Process logo path and convert to base64 for DomPDF
        $logoBase64 = '';
        if ($institution && !empty($institution['logo_path'])) {
            $logoPath = $institution['logo_path'];
            if (strpos($logoPath, 'res.cloudinary.com') !== false && 
                strpos($logoPath, 'http') === false) {
                $logoPath = 'https://' . ltrim($logoPath, '/');
            }
            
            try {
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                    'http' => [
                        'ignore_errors' => true,
                        'timeout' => 10
                    ]
                ]);
                
                $imageData = file_get_contents($logoPath, false, $context);
                if ($imageData !== false) {
                    $imageInfo = @getimagesizefromstring($imageData);
                    if ($imageInfo) {
                        $mimeType = $imageInfo['mime'];
                        $logoBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                    }
                }
            } catch (\Exception $e) {
                error_log('Error loading logo for PDF: ' . $e->getMessage());
            }
        }

        $isSub = $suborderId !== null;
        $suborder = $isSub ? $suborderRepo->getOne(["id" => $suborderId]) : null;

        // Usar hora local del servidor sin conversiones de timezone
        $timestamp = date("F j, Y - g:i A");
        $institutionName = $institution["name"] ?? "";
        $institutionAddress = $institution["address"] ?? "";
        $institutionPhone = $institution["phone"] ?? "";
        $institutionEmail = $institution["email"] ?? "";

        // Build order items and totals
        $rows = '';
        $subtotal = 0.0;
        if ($isSub) {
            $subAssignRepo = new OrderSuborderServicesAssignedRepository();
            $subItems = $subAssignRepo->getServicesWithDetails((int)$suborderId);
            foreach ($subItems as $it) {
                $unit = (float)($it->actual_price ?? 0);
                $line = $unit * (float)$it->quantity;
                $subtotal += $line;
                $rows .= '<tr>
                    <td>' . htmlspecialchars($it->service_name ?? '') . '</td>
                    <td style="text-align:center;">' . (int)$it->quantity . '</td>
                    <td style="text-align:right;">$' . number_format($unit, 2) . '</td>
                    <td style="text-align:right;">$' . number_format($line, 2) . '</td>
                </tr>';
                
                // Mostrar descripción histórica si existe (viene de getServicesWithDetails que ya usa la histórica)
                if (!empty($it->service_description) && ($it->is_variable ?? 'NO') !== 'YES') {
                    $rows .= '<tr><td colspan="4" style="font-size:8px;color:#6b7a85;padding-left:20px;">• ' . htmlspecialchars($it->service_description) . '</td></tr>';
                }
            }
            $discountType = $suborder->discount_type ?? 'amount';
            $discountValue = (float)($suborder->discount_value ?? 0);
            // El discount_value ya es el monto real calculado, no necesitamos recalcular
            $discountAmount = $discountValue;
            $discountAmount = max(0.0, $discountAmount);
            $base = max($subtotal - $discountAmount, 0.0);
            $taxPercent = (float)($suborder->tax_percertance ?? 0);
        } else {
            $assignRepo = new OrdersServicesAssignedRepository();
            $serviceRepo = new OrdersServiceRepository();
            $assigned = $assignRepo->getAllBy(["id_order" => $order->id]);
            foreach ($assigned as $it) {
                $service = $serviceRepo->getOne(["id" => $it->id_service]);
                if (!$service) { continue; }
                // Usar el precio histórico almacenado (unit_price) si existe
                if (isset($it->unit_price) && $it->unit_price > 0) {
                    $unit = (float)$it->unit_price;
                } else {
                    // Fallback para órdenes antiguas
                    $unit = ($it->is_variable === 'YES' && $it->variable_price !== null) ? (float)$it->variable_price : (float)$service->price;
                }
                $line = $unit * (float)$it->quantity;
                $subtotal += $line;
                $rows .= '<tr>
                    <td>' . htmlspecialchars($service->name) . '</td>
                    <td style="text-align:center;">' . (int)$it->quantity . '</td>
                    <td style="text-align:right;">$' . number_format($unit, 2) . '</td>
                    <td style="text-align:right;">$' . number_format($line, 2) . '</td>
                </tr>';
                
                // Usar la descripción histórica guardada si existe, sino usar la del servicio actual
                $description = null;
                if ($it->is_variable !== 'YES') {
                    if (isset($it->description) && $it->description) {
                        $description = $it->description; // Descripción histórica
                    } else {
                        $description = $service->description ?? null; // Fallback para órdenes antiguas
                    }
                }
                
                if (!empty($description)) {
                    $rows .= '<tr><td colspan="4" style="font-size:8px;color:#6b7a85;padding-left:20px;">• ' . htmlspecialchars($description) . '</td></tr>';
                }
            }
            $discountType = $order->discount_type ?? 'amount';
            $discountValue = (float)($order->discount_value ?? 0);
            // El discount_value ya es el monto real calculado, no necesitamos recalcular
            $discountAmount = $discountValue;
            $discountAmount = max(0.0, $discountAmount);
            $base = max($subtotal - $discountAmount, 0.0);
            $taxPercent = (float)($order->tax_percentage ?? 0);
        }
        $taxAmount = $base * ($taxPercent / 100);
        
        $tipAmount = 0;
        $tipPercentage = 0;
        if (!$isSub && !empty($order->id_tip)) {
            $tipsRepo = new \App\Repositories\TipsRepository();
            $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
            if ($tip && $tip->is_active == 1) {
                $tipAmount = $base * ($tip->percentage / 100);
                $tipPercentage = $tip->percentage;
            }
        }
        
        $total = $base + $taxAmount + $tipAmount;

        // Get payment history for this order/suborder
        $paymentHistory = '';
        $totalPaid = 0.0;
        $advancesTotal = 0.0;
        
        if ($isSub) {
            $payments = $paymentsRepo->getAllBy(["id_suborder" => $suborderId]);
            // Get advances for this suborder
            try {
                $db = new \App\Repositories\Connection();
                $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
                $db->bind(":id", $suborderId);
                $db->execute();
                $row = $db->fetchAll()[0] ?? null;
                $advancesTotal = (float)($row->total_advanced ?? 0);
            } catch (\Throwable $e) {
                $advancesTotal = 0.0;
            }
        } else {
            $payments = $paymentsRepo->getAllBy(["id_order" => $orderId, "id_suborder" => null]);
            // Get advances for this order (including suborders)
            try {
                $db = new \App\Repositories\Connection();
                // Advances applied directly to the order
                $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
                $db->bind(":id", $orderId);
                $db->execute();
                $row = $db->fetchAll()[0] ?? null;
                $advancesTotal += (float)($row->total_advanced ?? 0);

                // Advances applied to suborders of this order
                $db->query("SELECT COALESCE(SUM(oa.amount),0) AS total_advanced FROM orders_advances oa INNER JOIN orders_suborder s ON s.id = oa.id_suborder WHERE oa.is_suborder = 1 AND s.id_order = :id");
                $db->bind(":id", $orderId);
                $db->execute();
                $row2 = $db->fetchAll()[0] ?? null;
                $advancesTotal += (float)($row2->total_advanced ?? 0);
            } catch (\Throwable $e) {
                $advancesTotal = 0.0;
            }
        }
        
        foreach ($payments as $payment) {
            $paymentDate = date("M j, Y", strtotime($payment->paid_at));
            $paymentMethod = ucfirst($payment->method);
            $totalPaid += (float)$payment->amount;
            $paymentHistory .= $paymentDate . ' (' . $paymentMethod . ') $' . number_format((float)$payment->amount, 2) . '<br>';
        }

        $totalPaidAndAdvances = $totalPaid + $advancesTotal;
        $remainingBalance = max($total - $totalPaidAndAdvances, 0);
        $depositAmount = min($totalPaidAndAdvances, $total);

        // Determine payment status
        $depositStatus = $depositAmount > 0 ? 'Paid' : 'Unpaid';
        $balanceStatus = $remainingBalance > 0 ? 'Unpaid' : 'Paid';
        
        // Auto-generate payment concept if not provided
        if (empty($paymentConcept)) {
            if ($isSub) {
                $paymentConcept = 'Suborder Payment - Sub-' . $suborderId;
            } else {
                if ($remainingBalance <= 0) {
                    $paymentConcept = 'Full Payment';
                } else {
                    $paymentConcept = 'First Installment Payment';
                }
            }
        }

        $html = '
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 50px 50px; }
                body { font-family: Arial, sans-serif; color: #152026; font-size: 9px; margin: 0; padding: 0; }
                .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
                .company-info { color: #152026; font-size: 8px; }
                .company-name { font-weight: bold; font-size: 8px; }
                .invoice-info { text-align: right; color: #152026; font-size: 8px; }
                .invoice-number { font-weight: bold; font-size: 8px; }
                .hr-thick { height: 4px; background: #4c6b7d; margin: 15px 0; }
                .title { font-size: 20px; font-weight: bold; color: #152026; margin: 20px 0 5px; }
                .subtitle { font-size: 9px; color: #152026; margin-bottom: 20px; }
                .grid-4 { display: table; width: 100%; margin-bottom: 15px; }
                .grid-row { display: table-row; }
                .grid-cell { display: table-cell; width: 25%; border: 1px solid #d6dde3; padding: 8px; background: #f8f9fa; vertical-align: top; }
                .grid-header { font-weight: bold; color: #152026; font-size: 9px; margin-bottom: 3px; }
                .grid-content { color: #152026; font-size: 9px; line-height: 1.2; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th { background: #c8ddf0; border: 1px solid #d6dde3; padding: 8px; text-align: center; font-weight: bold; font-size: 9px; }
                td { border: 1px solid #d6dde3; padding: 8px; font-size: 9px; }
                .totals-table { width: 50%; margin-left: auto; margin-top: 15px; }
                .totals-table td { border: none; padding: 4px 8px; font-size: 9px; }
                .total-row { font-weight: bold; font-size: 10px; }
                .footer { margin-top: 30px; text-align: center; color: #6b7a85; font-size: 8px; }
                .page-break { page-break-before: always; }
                .payment-section { margin-top: 30px; }
                .payment-grid { display: table; width: 100%; margin: 20px 0; }
                .payment-grid-row { display: table-row; }
                .payment-grid-cell { display: table-cell; width: 50%; border: 1px solid #d6dde3; padding: 15px; vertical-align: top; }
                .payment-header { font-weight: bold; font-size: 11px; margin-bottom: 8px; }
                .payment-amount { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .payment-status { font-size: 9px; color: #6b7a85; }
                .payment-history { margin-top: 20px; }
                .payment-history h3 { font-size: 11px; font-weight: bold; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <!-- Page 1 -->
            <div class="header">
                <div style="display: flex; align-items: center;">
                    ' . (!empty($logoBase64) ? 
                        '<img src="' . $logoBase64 . '" alt="Logo" style="height: 40px; margin-right: 15px;">' : '') . '
                    <div class="company-info">
                        <div class="company-name">' . htmlspecialchars($institutionName) . '</div>
                        <div>' . htmlspecialchars($institutionEmail) . ' | ' . htmlspecialchars($institutionPhone) . '</div>
                    </div>
                </div>
                <div class="invoice-info">
                    <div class="invoice-number">Invoice #0001300</div>
                    <div>Issue Date</div>
                    <div>' . date("M j, Y", strtotime($order->created_at ?? 'now')) . '</div>
                </div>
            </div>

            <div class="hr-thick"></div>

            <div class="title">Order Summary</div>
            <div class="subtitle">Thank you for your business.</div>

            <!-- Grid de 4 columnas: Client | Invoice Info | Deposit | Balance -->
            <div class="grid-4">
                <div class="grid-row">
                    <div class="grid-cell">
                        <div class="grid-header">Client</div>
                        <div class="grid-content">' . htmlspecialchars($client->name . ' ' . $client->lastname) . '</div>
                        <div class="grid-content">' . htmlspecialchars($institutionName) . '</div>
                        <div class="grid-content">' . htmlspecialchars($client->email) . '</div>
                        <div class="grid-content">' . htmlspecialchars($client->phone ?? '') . '</div>
                    </div>
                    <div class="grid-cell">
                        <div class="grid-header">Invoice Information</div>
                        <div class="grid-content">PDF created on ' . date("F j, Y") . '</div>
                        <div class="grid-content">$' . number_format($total, 2) . '</div>
                        <div class="grid-content">Service Date: ' . date("F j, Y", strtotime($order->event_date)) . '</div>
                    </div>
                    <div class="grid-cell">
                        <div class="grid-header">Deposit</div>
                        <div class="grid-content">To pay on ' . date("M j, Y", strtotime($order->event_date)) . '</div>
                        <div class="grid-content">$' . number_format($depositAmount, 2) . '</div>
                    </div>
                    <div class="grid-cell">
                        <div class="grid-header">Balance</div>
                        <div class="grid-content">To pay on ' . date("M j, Y", strtotime($order->event_date)) . '</div>
                        <div class="grid-content">$' . number_format($remainingBalance, 2) . '</div>
                    </div>
                </div>
            </div>

            <!-- Tabla de servicios -->
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left;">Articles</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $rows . '
                </tbody>
            </table>

            <!-- Totales -->
            <div class="totals-table">
                <table style="width: 100%;">
                    <tr><td>Subtotal</td><td style="text-align:right;">$' . number_format($subtotal, 2) . '</td></tr>';
        
        // Mostrar descuento si existe
        if ($discountAmount > 0) {
            if ($discountType === 'percentage') {
                // Calcular el porcentaje original basado en el subtotal y el monto del descuento
                $originalPercentage = ($subtotal > 0) ? round(($discountAmount / $subtotal) * 100, 2) : 0;
                $discountLabel = 'Discount (' . $originalPercentage . '%)';
            } else {
                $discountLabel = 'Discount';
            }
            $html .= '<tr><td>' . $discountLabel . '</td><td style="text-align:right;">-$' . number_format($discountAmount, 2) . '</td></tr>';
        }
        
        $html .= '                    <tr><td>Tax</td><td style="text-align:right;">$' . number_format($taxAmount, 2) . '</td></tr>';
        
        if ($tipAmount > 0) {
            $html .= '                    <tr><td>Tip (' . $tipPercentage . '%)</td><td style="text-align:right;">$' . number_format($tipAmount, 2) . '</td></tr>';
        }
        
        $html .= '                    <tr class="total-row"><td>Total to pay</td><td style="text-align:right;">$' . number_format($total, 2) . '</td></tr>
                </table>
            </div>

            <div class="footer">
                <div>Page 1 of 2</div>
            </div>

            <!-- Page 2 -->
            <div class="page-break">
                <div class="header">
                    <div style="display: flex; align-items: center;">
                        ' . (!empty($logoBase64) ? 
                            '<img src="' . $logoBase64 . '" alt="Logo" style="height: 40px; margin-right: 15px;">' : '') . '
                        <div class="company-info">
                            <div class="company-name">' . htmlspecialchars($institutionName) . '</div>
                            <div>' . htmlspecialchars($institutionEmail) . ' | ' . htmlspecialchars($institutionPhone) . '</div>
                        </div>
                    </div>
                    <div class="invoice-info">
                        <div class="invoice-number">Invoice #0001300</div>
                        <div>Issue Date</div>
                        <div>' . date("M j, Y", strtotime($order->created_at ?? 'now')) . '</div>
                    </div>
                </div>

                <div class="payment-section">
                    <!-- Payment Grid -->
                    <div class="payment-grid">
                        <div class="payment-grid-row">
                            <div class="payment-grid-cell">
                                <div class="payment-header">Deposit</div>
                                <div class="payment-amount">$' . number_format($depositAmount, 2) . '</div>
                                <div class="payment-status">' . $depositStatus . ' • Due on ' . date("M j, Y", strtotime($order->event_date)) . '</div>
                            </div>
                            <div class="payment-grid-cell">
                                <div class="payment-header">Balance</div>
                                <div class="payment-amount">$' . number_format($remainingBalance, 2) . '</div>
                                <div class="payment-status">' . $balanceStatus . ' • Due on ' . date("M j, Y", strtotime($order->event_date)) . '</div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div class="payment-history">
                        <h3>Payments</h3>
                        <div style="font-size: 9px; line-height: 1.4;">' . 
                        ($advancesTotal > 0 ? date("M j, Y") . ' (Advance) $' . number_format($advancesTotal, 2) . '<br>' : '') .
                        ($paymentHistory ?: date("M j, Y") . ' (Effective) $' . number_format($depositAmount, 2)) . '
                        </div>
                    </div>
                    
                    <!-- Payment Concept -->
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #d6dde3;">
                        <div style="font-weight: bold; font-size: 11px; margin-bottom: 5px;">Payment Concept</div>
                        <div style="font-size: 10px; color: #152026;">' . htmlspecialchars($paymentConcept) . '</div>
                        <div style="font-size: 9px; color: #6b7a85; margin-top: 3px;">Amount: $' . number_format($amountPaid, 2) . ' via ' . htmlspecialchars($paymentMethodLabel) . '</div>
                    </div>
                    
                    <!-- Payment Summary and Method -->
                    ' . self::generatePaymentDetailsSection($payments, $amountPaid, $totalPaid, $order->id_owner) . '
                </div>

                <div class="footer">
                    <div>Page 2 of 2</div>
                </div>
            </div>
        </body>
        </html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $content = $dompdf->output();
        
        if (empty($content)) {
            throw new Exception("Failed to generate PDF content");
        }
        
        $filePath = FileUtils::saveFileFromContent($content, 'documents_contracts', 'pdf');
        
        if (empty($filePath)) {
            throw new Exception("Failed to save PDF file");
        }
        
        return $filePath;
    }

    private static function generatePaymentDetailsSection(array $payments, float $amountPaid, float $totalPaid, ?int $ownerId = null): string
    {
        // Encontrar el pago más reciente que corresponde al amountPaid
        $latestPayment = null;
        foreach ($payments as $payment) {
            if (abs((float)$payment->amount - $amountPaid) < 0.01) {
                $latestPayment = $payment;
                break;
            }
        }
        
        // Si no encontramos uno exacto, usar el último pago
        if (!$latestPayment && !empty($payments)) {
            $latestPayment = end($payments);
        }

        $paymentSummaryHtml = '';
        $paymentMethodHtml = '';

        if ($latestPayment) {
            // Payment Summary
            $paymentSummaryHtml = '
                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #d6dde3;">
                    <div style="font-weight: bold; font-size: 11px; margin-bottom: 10px; color: #152026;">Payment Summary</div>
                    <div style="font-size: 9px; color: #152026; margin-bottom: 5px;">Payments Made: $' . number_format($totalPaid, 2) . '</div>
                    <div style="font-size: 9px; color: #152026;">Total Paid: $' . number_format($totalPaid, 2) . '</div>
                </div>';

            // Payment Method
            $cardBrand = $latestPayment->card_brand ?? null;
            $cardLast4 = $latestPayment->card_last4 ?? null;
            $cardExpMonth = $latestPayment->card_exp_month ?? null;
            $cardExpYear = $latestPayment->card_exp_year ?? null;
            
            if ((empty($cardBrand) || empty($cardLast4)) && !empty($latestPayment->stripe_charge_id) && $ownerId) {
                try {
                    $stripeAccountsRepo = new \App\Repositories\StripeAccountsRepository();
                    $stripeAccount = $stripeAccountsRepo->getByUser($ownerId);
                    
                    if ($stripeAccount && !empty($stripeAccount->stripe_account_id)) {
                        $stripeService = new \App\Services\StripeServiceV2();
                        $chargeId = $latestPayment->stripe_charge_id;
                        
                        $paymentIntent = null;
                        try {
                            if (str_starts_with($chargeId, 'pi_')) {
                                $paymentIntent = @$stripeService->getPaymentIntent($chargeId, $stripeAccount->stripe_account_id);
                            } elseif (str_starts_with($chargeId, 'ch_')) {
                                $charge = @$stripeService->getCharge($chargeId, $stripeAccount->stripe_account_id);
                                if ($charge && isset($charge->payment_intent)) {
                                    if (is_string($charge->payment_intent)) {
                                        $paymentIntent = @$stripeService->getPaymentIntent($charge->payment_intent, $stripeAccount->stripe_account_id);
                                        if ($paymentIntent) {
                                            $paymentIntent->latest_charge = $charge;
                                        }
                                    } else {
                                        $paymentIntent = $charge->payment_intent;
                                        $paymentIntent->latest_charge = $charge;
                                    }
                                } elseif ($charge) {
                                    // Usar charge directamente como payment intent-like object
                                    $paymentIntent = (object)[
                                        'payment_method' => $charge->payment_method ?? null,
                                        'latest_charge' => $charge
                                    ];
                                }
                            }
                            
                            if ($paymentIntent) {
                                $cardDetails = \App\Services\PaymentCardExtractor::extractCardDetails(
                                    $paymentIntent,
                                    $stripeService,
                                    $stripeAccount->stripe_account_id
                                );
                                
                                if (empty($cardBrand) && !empty($cardDetails['brand'])) {
                                    $cardBrand = $cardDetails['brand'];
                                }
                                if (empty($cardLast4) && !empty($cardDetails['last4'])) {
                                    $cardLast4 = $cardDetails['last4'];
                                }
                                if (empty($cardExpMonth) && !empty($cardDetails['exp_month'])) {
                                    $cardExpMonth = $cardDetails['exp_month'];
                                }
                                if (empty($cardExpYear) && !empty($cardDetails['exp_year'])) {
                                    $cardExpYear = $cardDetails['exp_year'];
                                }
                            }
                        } catch (\Exception $stripeEx) {
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            
            if (!empty($cardBrand) || !empty($cardLast4)) {
                $cardType = !empty($cardBrand) ? ucfirst($cardBrand) . ' Credit Card' : 'Credit Card';
                $cardNumber = !empty($cardLast4) ? 'XXXX-XXXX-XXXX-' . $cardLast4 : 'XXXX-XXXX-XXXX-XXXX';
                $cardExp = '';
                if (!empty($cardExpMonth) && !empty($cardExpYear)) {
                    $cardExp = str_pad($cardExpMonth, 2, '0', STR_PAD_LEFT) . '/' . $cardExpYear;
                }

                $paymentMethodHtml = '
                <div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #d6dde3;">
                    <div style="font-weight: bold; font-size: 11px; margin-bottom: 10px; color: #152026;">Payment Method</div>
                    <div style="font-size: 9px; color: #152026; margin-bottom: 5px;">Type: ' . htmlspecialchars($cardType) . '</div>
                    <div style="font-size: 9px; color: #152026; margin-bottom: 5px;">Number: ' . htmlspecialchars($cardNumber) . '</div>';
                
                if ($cardExp) {
                    $paymentMethodHtml .= '<div style="font-size: 9px; color: #152026;">Expires on: ' . htmlspecialchars($cardExp) . '</div>';
                }
                
                $paymentMethodHtml .= '</div>';
            }
        }

        return $paymentSummaryHtml . $paymentMethodHtml;
    }
}