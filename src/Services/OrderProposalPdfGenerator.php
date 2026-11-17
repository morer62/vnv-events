<?php

namespace App\Services;

use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\FileUtils;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class OrderProposalPdfGenerator
{
    /** @return array{file_path:string,hash:string,content:string} */
    public function generateAndSave(object $proposal, object $revision, string $signatureMethod, string $signatureValue, ?string $userTimestamp): array
    {
        $orderData = (new OrdersRepository())->getByIdWithoutOwnershipCheck((int)$proposal->id_order);
        $order = $orderData ? (object)$orderData : null;
        if (!$order) {
            throw new RuntimeException('Order not found while generating proposal approval.');
        }

        $client = (new UserRepository())->getOne(['id' => (int)$order->id_client]);
        $institution = (new InstitutionProfileRepository())->getByOwner((int)$order->id_owner);
        $clientName = trim((string)(($client->name ?? '') . ' ' . ($client->lastname ?? ''))) ?: (string)($client->email ?? 'Client');
        $companyName = (string)($institution->company_name ?? $institution->name ?? 'VNV Events');
        $guestCount = (new OrderGuestCountService())->resolve($order);
        $signedAt = $userTimestamp ? date('Y-m-d H:i:s', strtotime($userTimestamp)) : date('Y-m-d H:i:s');
        $displayDate = date('F j, Y - g:i A', strtotime($signedAt));

        $signatureHtml = '<div class="typed-signature">' . htmlspecialchars($signatureValue, ENT_QUOTES, 'UTF-8') . '</div>';
        if ($signatureMethod === 'upload') {
            $signatureHtml = '<img class="signature-image" src="' . htmlspecialchars($signatureValue, ENT_QUOTES, 'UTF-8') . '" alt="Client signature">';
        }

        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
            @page { margin: 42px 48px 54px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#152026; font-size:11px; line-height:1.55; }
            .header { border-bottom:4px solid #0b8f84; padding-bottom:14px; margin-bottom:24px; }
            .brand { color:#0b5f59; font-size:18px; font-weight:700; }
            h1 { font-size:24px; margin:16px 0 4px; } h2 { font-size:20px; margin:20px 0 8px; }
            h3 { font-size:15px; margin:16px 0 6px; } p { margin:0 0 10px; }
            small { display:block; color:#52606b; font-size:8.5px; line-height:1.5; }
            ul, ol { margin:0 0 12px 22px; padding:0; } li { margin:0 0 4px; }
            figure { margin:14px 0; } img { max-width:100%; height:auto; } figcaption { color:#66737d; font-size:9px; }
            .proposal-page-break { page-break-after:always; height:1px; }
            .meta { color:#52606b; margin-top:4px; }
            .signature { margin-top:34px; border:1px solid #cfe5e2; background:#f4fbfa; padding:16px; }
            .typed-signature { font-family:DejaVu Serif,serif; font-size:26px; font-style:italic; letter-spacing:.5px; margin:8px 0; }
            .signature-image { max-width:260px; max-height:90px; }
            .audit { color:#52606b; font-size:8px; margin-top:16px; border-top:1px solid #d8e0e6; padding-top:8px; }
        </style></head><body>
            <div class="header"><div class="brand">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</div>
            <h1>Menu / Proposal Approval</h1>
            <div class="meta">Order VNV-341' . (int)$order->id . ' &middot; Revision ' . (int)$revision->revision_number . ' &middot; Event ' . htmlspecialchars(date('F j, Y', strtotime((string)$order->event_date)), ENT_QUOTES, 'UTF-8') . ($guestCount ? ' &middot; Guests ' . $guestCount : '') . '</div></div>
            <main>' . (string)$revision->content_html . '</main>
            <section class="signature"><strong>Approved and electronically signed by ' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '</strong><br>' . $signatureHtml . '<div>' . htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8') . '</div></section>
            <div class="audit">Revision ID: ' . (int)$revision->id . '<br>IP: ' . htmlspecialchars((string)($_SERVER['REMOTE_ADDR'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>User agent: ' . htmlspecialchars((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>
        </body></html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $content = $dompdf->output();

        return [
            'file_path' => FileUtils::saveFileFromContent($content, 'documents_proposal_approvals', 'pdf'),
            'hash' => hash('sha256', $content),
            'content' => $content,
        ];
    }
}
