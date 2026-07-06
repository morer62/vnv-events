<?php

namespace App\Services;

use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrdersAcceptanceContractTemplateRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class OrderAcceptancePdfGenerator
{
    /**
     * Generates the signed order acceptance PDF and returns its public URL and content hash.
     *
     * @return array{file_path: string, hash: string}
     */
    public static function generateAndSave(int $orderId, ?string $userTimestamp = null, ?string $signatureImagePath = null): array
    {
        $orderRepo = new OrdersRepository();
        $userRepo = new UserRepository();
        $institutionRepo = new InstitutionProfileRepository();
        $templateRepo = new OrdersAcceptanceContractTemplateRepository();

        $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
        if ($order) {
            $order = (object)$order;
        }
        if (!$order) {
            throw new Exception('Order not found');
        }

        $client = $userRepo->getOne(['id' => $order->id_client]);
        $institution = $institutionRepo->getByOwner((int)$order->id_owner);
        $institutionData = $institution ? json_decode(json_encode($institution), true) : [];
        $template = $templateRepo->getOrCreateByOwner((int)$order->id_owner);

        $acceptanceText = (string)($template->content ?? TranslationService::trans('planner_hub.accept_order_confirmation'));
        $acceptanceText = str_replace('#ORDER_ID#', 'VNV-341' . $order->id, $acceptanceText);

        $timestamp = $userTimestamp ? date('F j, Y - g:i A', strtotime($userTimestamp)) : date('F j, Y - g:i A');
        $storedTimestamp = $userTimestamp ? date('Y-m-d H:i:s', strtotime($userTimestamp)) : date('Y-m-d H:i:s');
        $ip = ($_SERVER['REMOTE_ADDR'] ?? '') === '::1' ? '127.0.0.1' : ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        $browser = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $typedInitials = trim((string)($_POST['typed_initials'] ?? ''));

        $companyName = $institutionData['company_name'] ?? $institutionData['name'] ?? 'VNV Events LLC';
        $companyEmail = $institutionData['email'] ?? '';
        $companyPhone = $institutionData['phone'] ?? '';
        $clientName = trim((string)(($client->name ?? '') . ' ' . ($client->lastname ?? ''))) ?: ($client->email ?? 'Client');
        $clientEmail = $client->email ?? '';

        $signatureHtml = '<div class="signature-text">' . htmlspecialchars($typedInitials ?: $clientName) . '</div>';
        if ($signatureImagePath) {
            $signatureHtml = '<img src="' . htmlspecialchars($signatureImagePath) . '" class="signature-image" alt="Client signature">';
        }

        $html = '
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 46px 48px; }
                body { font-family: Arial, sans-serif; color: #152026; font-size: 11px; line-height: 1.45; }
                .top { display: table; width: 100%; margin-bottom: 18px; }
                .brand, .meta { display: table-cell; vertical-align: top; width: 50%; }
                .brand-title { font-size: 18px; font-weight: bold; color: #0b5f59; margin-bottom: 4px; }
                .meta { text-align: right; color: #52606b; }
                .bar { height: 4px; background: #0b8f84; margin: 14px 0 22px; }
                h1 { font-size: 24px; margin: 0 0 4px; color: #111820; }
                .subtitle { color: #52606b; margin-bottom: 18px; }
                .grid { display: table; width: 100%; border-collapse: collapse; margin: 18px 0; }
                .row { display: table-row; }
                .cell { display: table-cell; width: 33.33%; border: 1px solid #d8e0e6; padding: 10px; vertical-align: top; }
                .label { color: #52606b; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; font-weight: bold; margin-bottom: 4px; }
                .value { font-size: 11px; color: #152026; }
                .acceptance { border: 1px solid #cfe5e2; background: #f4fbfa; padding: 14px; margin: 18px 0; }
                .signature { margin-top: 26px; border-top: 1px solid #8aa09d; width: 260px; padding-top: 8px; }
                .signature-text { font-family: DejaVu Sans, Arial, sans-serif; font-size: 22px; color: #152026; }
                .signature-image { max-height: 80px; max-width: 240px; }
                .audit { margin-top: 22px; color: #52606b; font-size: 9px; border-top: 1px solid #d8e0e6; padding-top: 10px; }
            </style>
        </head>
        <body>
            <div class="top">
                <div class="brand">
                    <div class="brand-title">' . htmlspecialchars($companyName) . '</div>
                    <div>' . htmlspecialchars($companyEmail) . '</div>
                    <div>' . htmlspecialchars($companyPhone) . '</div>
                </div>
                <div class="meta">
                    <strong>Order Acceptance #VNV-341' . (int)$order->id . '</strong><br>
                    Generated: ' . htmlspecialchars($timestamp) . '
                </div>
            </div>
            <div class="bar"></div>

            <h1>Order Receipt Acceptance</h1>
            <div class="subtitle">Confirmation of receipt in accordance with the amount paid for Order VNV-341' . (int)$order->id . '.</div>

            <div class="grid">
                <div class="row">
                    <div class="cell">
                        <div class="label">Client</div>
                        <div class="value">' . htmlspecialchars($clientName) . '</div>
                        <div class="value">' . htmlspecialchars($clientEmail) . '</div>
                    </div>
                    <div class="cell">
                        <div class="label">Event</div>
                        <div class="value">' . htmlspecialchars(date('F j, Y', strtotime((string)$order->event_date))) . '</div>
                        <div class="value">' . htmlspecialchars((string)($order->start_time ?? '')) . ' - ' . htmlspecialchars((string)($order->end_time ?? '')) . '</div>
                    </div>
                    <div class="cell">
                        <div class="label">Location</div>
                        <div class="value">' . htmlspecialchars((string)($order->address ?? '')) . '</div>
                    </div>
                </div>
            </div>

            <div class="acceptance">' . $acceptanceText . '</div>

            <div class="signature">
                ' . $signatureHtml . '
                <div class="label">Electronic Signature</div>
                <div class="value">' . htmlspecialchars($storedTimestamp) . '</div>
            </div>

            <div class="audit">
                This document was electronically generated and signed by VNV Events LLC.<br>
                IP: ' . htmlspecialchars($ip) . '<br>
                Browser: ' . htmlspecialchars($browser) . '
            </div>
        </body>
        </html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
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
