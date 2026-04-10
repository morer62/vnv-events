<?php

namespace App\Services;

use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;

class TipReceiptPdfGenerator
{
    public static function generateAndSave(int $orderId, float $tipAmount, string $paymentMethodLabel = 'Credit Card', ?array $cardDetails = null): ?string
    {
        $orderRepo = new OrdersRepository();
        $userRepo = new UserRepository();
        $institutionRepo = new InstitutionProfileRepository();

        $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
        if (!$order) {
            return null;
        }

        if (is_array($order)) {
            $order = (object)$order;
        }

        $client = $userRepo->getOne(["id" => $order->id_client]);
        $institution = $institutionRepo->getByOwner($order->id_owner);
        $institution = json_decode(json_encode($institution), true);

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
            }
        }

        $timestamp = date("F j, Y - g:i A");
        $institutionName = $institution["name"] ?? "";
        $institutionAddress = $institution["address"] ?? "";
        $institutionPhone = $institution["phone"] ?? "";
        $institutionEmail = $institution["email"] ?? "";

        $orderTotal = $orderRepo->calculateTotal($orderId);

        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 20px; }
                body { font-family: Arial, sans-serif; font-size: 10px; color: #152026; margin: 0; padding: 0; }
                .header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #d6dde3; }
                .logo { max-width: 150px; max-height: 60px; margin-bottom: 10px; }
                .company-name { font-size: 18px; font-weight: bold; color: #152026; margin: 5px 0; }
                .company-details { font-size: 9px; color: #6b7a85; }
                .receipt-title { font-size: 24px; font-weight: bold; color: #198754; text-align: center; margin: 20px 0; }
                .info-section { margin: 20px 0; }
                .info-row { display: table; width: 100%; margin: 5px 0; }
                .info-label { display: table-cell; width: 30%; font-weight: bold; color: #6b7a85; }
                .info-value { display: table-cell; width: 70%; color: #152026; }
                .amount-box { background: #f8f9fa; border: 2px solid #198754; border-radius: 8px; padding: 20px; margin: 30px 0; text-align: center; }
                .amount-label { font-size: 12px; color: #6b7a85; margin-bottom: 5px; }
                .amount-value { font-size: 32px; font-weight: bold; color: #198754; }
                .payment-details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .payment-details h3 { font-size: 12px; margin: 0 0 10px 0; color: #152026; }
                .thank-you { text-align: center; margin: 30px 0; padding: 20px; background: #e7f5f0; border-radius: 5px; }
                .thank-you-text { font-size: 16px; font-weight: bold; color: #198754; }
                .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #d6dde3; font-size: 9px; color: #6b7a85; }
            </style>
        </head>
        <body>
            <div class="header">';

        if ($logoBase64) {
            $html .= '<img src="' . $logoBase64 . '" class="logo" alt="Logo">';
        }

        $html .= '
                <div class="company-name">' . htmlspecialchars($institutionName) . '</div>
                <div class="company-details">' . htmlspecialchars($institutionAddress) . '</div>
                <div class="company-details">' . htmlspecialchars($institutionPhone) . ' | ' . htmlspecialchars($institutionEmail) . '</div>
            </div>

            <div class="receipt-title">TIP RECEIPT</div>

            <div class="info-section">
                <div class="info-row">
                    <div class="info-label">Receipt Date:</div>
                    <div class="info-value">' . $timestamp . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Order ID:</div>
                    <div class="info-value">VNV-341' . $order->id . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Client Name:</div>
                    <div class="info-value">' . htmlspecialchars($client->name . ' ' . $client->lastname) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Event Date:</div>
                    <div class="info-value">' . date("F j, Y", strtotime($order->event_date)) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Order Total:</div>
                    <div class="info-value">$' . number_format($orderTotal, 2) . '</div>
                </div>
            </div>

            <div class="amount-box">
                <div class="amount-label">TIP AMOUNT</div>
                <div class="amount-value">$' . number_format($tipAmount, 2) . '</div>
            </div>

            <div class="payment-details">
                <h3>Payment Information</h3>
                <div class="info-row">
                    <div class="info-label">Payment Method:</div>
                    <div class="info-value">' . htmlspecialchars($paymentMethodLabel) . '</div>
                </div>';

        if ($cardDetails && !empty($cardDetails['last4'])) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Card Type:</div>
                    <div class="info-value">' . htmlspecialchars(ucfirst($cardDetails['brand'] ?? 'Card')) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Card Number:</div>
                    <div class="info-value">**** **** **** ' . htmlspecialchars($cardDetails['last4']) . '</div>
                </div>';
            
            if (!empty($cardDetails['exp_month']) && !empty($cardDetails['exp_year'])) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Expires:</div>
                    <div class="info-value">' . str_pad($cardDetails['exp_month'], 2, '0', STR_PAD_LEFT) . '/' . $cardDetails['exp_year'] . '</div>
                </div>';
            }
        }

        $html .= '
                <div class="info-row">
                    <div class="info-label">Transaction Status:</div>
                    <div class="info-value" style="color: #198754; font-weight: bold;">Completed</div>
                </div>
            </div>

            <div class="thank-you">
                <div class="thank-you-text">Thank You for Your Generosity!</div>
                <p style="margin: 10px 0 0 0; color: #6b7a85; font-size: 10px;">
                    Your gratuity is greatly appreciated and helps us continue providing excellent service.
                </p>
            </div>

            <div class="footer">
                <p>This is an official receipt for your tip payment.</p>
                <p>For questions or concerns, please contact us at ' . htmlspecialchars($institutionEmail) . '</p>
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

        $pdfOutput = $dompdf->output();

        try {
            $savedPath = FileUtils::saveFileFromContent($pdfOutput, 'documents_contracts/', 'pdf');
            return $savedPath;
        } catch (\Exception $e) {
            error_log('Error saving tip receipt PDF: ' . $e->getMessage());
            return null;
        }
    }
}

