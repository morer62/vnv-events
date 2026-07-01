<?php

namespace App\Services;

use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class ContractPdfGenerator
{
    /**
     * Generates the signed contract PDF, uploads it, and returns the file URL and content hash for audit.
     * Hash is computed over the final PDF bytes for tamper-evident contract records.
     *
     * @return array{file_path: string, hash: string}
     */
    public static function generateAndSave(int $orderId, ?string $userTimestamp = null): array
    {
        $orderRepo = new OrdersRepository();
        $userRepo = new UserRepository();
        $contractRepo = new OrdersContractRepository();
        $institutionRepo = new InstitutionProfileRepository();

        $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
        if ($order) {
            $order = (object)$order;
        }
        if (!$order) throw new Exception("Order not found");

        $client = $userRepo->getOne(["id" => $order->id_client]);
        $institution = $institutionRepo->getByOwner($order->id_owner);
        $institution = json_decode(json_encode($institution), true);
        $contract = $order->id_contract ? $contractRepo->getByIdWithoutOwnershipCheck($order->id_contract) : null;

       
        $ip = ($_SERVER["REMOTE_ADDR"] === '::1') ? '127.0.0.1' : ($_SERVER["REMOTE_ADDR"] ?? 'Unknown');

        $browser = $_SERVER["HTTP_USER_AGENT"] ?? 'Unknown';
        // Usar hora del usuario si está disponible, sino usar hora del servidor
        if ($userTimestamp) {
            $timestamp = date("F j, Y - g:i A", strtotime($userTimestamp));
        } else {
            $timestamp = date("F j, Y - g:i A");
        }

        // Process logo path and convert to base64 for DomPDF
        $logoBase64 = '';
        $institutionName = $institution["name"] ?? "";
        $institutionAddress = $institution["address"] ?? "";
        $institutionPhone = $institution["phone"] ?? "";
        $institutionEmail = $institution["email"] ?? "";

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
                error_log('Error loading logo for contract PDF: ' . $e->getMessage());
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
                .contract-content { margin: 20px 0; line-height: 1.5; font-size: 11px; }
                .meta { margin-top: 20px; font-size: 8px; color: #6b7a85; }
            </style>
        </head>
        <body>
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
                    <div class="invoice-number">Contract #VNV-341' . $order->id . '</div>
                    <div>Generated</div>
                    <div>' . $timestamp . '</div>
                </div>
            </div>

            <div class="hr-thick"></div>

            <div class="title">Service Agreement</div>
            <div class="subtitle">This agreement covers the services for order VNV-341' . $order->id . '.</div>

            <!-- Grid de 3 columnas: Client | Contract Info | Event Date -->
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
                        <div class="grid-header">Contract Information</div>
                        <div class="grid-content">Order ID: VNV-341' . $order->id . '</div>
                        <div class="grid-content">Venue: ' . htmlspecialchars($order->address ?? "") . '</div>
                        <div class="grid-content">Generated: ' . $timestamp . '</div>
                    </div>
                    <div class="grid-cell">
                        <div class="grid-header">Event Date</div>
                        <div class="grid-content">' . date("F j, Y", strtotime($order->event_date)) . '</div>
                    </div>
                    <div class="grid-cell">
                        <div class="grid-header">Signature</div>
                        <div class="grid-content">Electronically signed</div>
                        <div class="grid-content">IP: ' . $ip . '</div>
                        <div class="grid-content">' . $timestamp . '</div>
                    </div>
                </div>
            </div>

            <div class="contract-content">
                ' . ($contract ? $contract->content : '<p>No contract assigned.</p>') . '
            </div>

            <div class="meta">
                <hr style="height:1px; background:#d6dde3; border:0; margin:15px 0;"/>
                <div>This document was electronically generated and signed by VNV Events LLC.</div>
                <div>Browser: ' . htmlspecialchars($browser) . '</div>
            </div>
        </body>
        </html>';

        // 🖨️ DomPDF setup
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $content = $dompdf->output();

        $contentHash = hash('sha256', $content);
        $filePath = FileUtils::saveFileFromContent($content, 'documents_contracts', 'pdf');

        return [
            'file_path' => $filePath,
            'hash' => $contentHash,
        ];
    }
}
