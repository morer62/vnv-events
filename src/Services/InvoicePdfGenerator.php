<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

final class InvoicePdfGenerator
{
    public static function render(array $data, int|string $versionNumber): string
    {
        $invoice = $data['invoice']; $order = $data['order']; $company = $data['company']; $totals = $data['totals'];
        $e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $companyAddress = implode(', ', array_filter([$company['address_line1'] ?? '', $company['city'] ?? '', $company['state'] ?? '', $company['zip'] ?? '']));
        $lineRows = ''; $lastGroup = null;
        foreach ($data['lines'] as $line) {
            if ($lastGroup !== $line->invoice_group) { $lineRows .= '<tr><td colspan="4" class="group">'.$e($line->invoice_group).'</td></tr>'; $lastGroup = $line->invoice_group; }
            $lineRows .= '<tr><td><strong>'.$e($line->name).'</strong><div class="muted">'.$e($line->invoice_description).'</div></td><td class="center">'.number_format((float)$line->quantity, 0).'</td><td class="money">$'.number_format((float)$line->unit_price_effective, 2).'</td><td class="money">$'.number_format((float)$line->line_total, 2).'</td></tr>';
        }
        if ($lineRows === '') $lineRows = '<tr><td colspan="4" class="muted">No service lines recorded.</td></tr>';
        $paymentRows = '';
        foreach ($data['payments'] as $payment) {
            $card = trim((string)($payment->card_brand ?? '').' '.(!empty($payment->card_last4) ? '•••• '.$payment->card_last4 : ''));
            $paymentRows .= '<tr><td>'.$e(date('M j, Y', strtotime((string)$payment->paid_at))).'<div class="muted">'.$e($payment->source_label ?? '').'</div></td><td>'.$e(ucwords(str_replace('_', ' ', (string)$payment->method))).($card ? ' · '.$e($card) : '').'</td><td>'.$e($payment->reference_id ?: 'Manual record').'</td><td class="money">$'.number_format((float)$payment->amount, 2).'</td><td class="money">'.((float)$payment->refunded_amount > 0 ? '-$'.number_format((float)$payment->refunded_amount, 2) : '—').'</td></tr>';
        }
        if ($paymentRows === '') $paymentRows = '<tr><td colspan="5" class="muted">No payments recorded.</td></tr>';
        $statusLabel = str_replace('_', ' ', (string)$invoice->status);
        $paidStamp = (string)$invoice->status === 'PAID' ? '<div class="paid">PAID IN FULL</div>' : '';
        $logo = !empty($company['logo_path']) ? '<img src="'.$e(str_starts_with((string)$company['logo_path'], 'http') ? $company['logo_path'] : 'https://'.ltrim((string)$company['logo_path'], '/')).'" style="max-height:52px;max-width:150px;margin-bottom:6px">' : '';
        $taxLabel = $totals['tax_rate'] === null ? 'Sales Tax (consolidated)' : 'Sales Tax ('.number_format($totals['tax_rate'], 2).'%)';
        $suborderLabel = !empty($data['suborders']) ? ' · '.count($data['suborders']).' suborder(s)' : '';
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:letter;margin:34px}body{font:11px Arial,sans-serif;color:#17362f}.top{border-top:5px solid #0b7d6d;padding-top:14px}.brand{font-size:24px;font-weight:700}.title{font-size:26px;text-align:right}.muted{color:#66756f;font-size:9px;line-height:1.4}.grid{width:100%;margin:18px 0}.grid td{vertical-align:top;width:50%}.box{border:1px solid #d8e4e0;border-radius:7px;padding:10px}table.lines,table.payments,table.totals{width:100%;border-collapse:collapse;margin-top:12px}th{background:#edf7f4;text-align:left;padding:7px;border-bottom:1px solid #c9ddd7}td{padding:7px;border-bottom:1px solid #e3ebe8;vertical-align:top}.group{background:#dcefe9;font-weight:bold;color:#0b665a}.center{text-align:center}.money{text-align:right;white-space:nowrap}.totals{margin-left:auto;width:42%!important}.totals td:first-child{font-weight:600}.grand td{font-size:14px;border-top:2px solid #0b7d6d}.paid{border:3px solid #16856d;color:#16856d;font-size:23px;font-weight:bold;text-align:center;padding:8px;transform:rotate(-3deg);margin:14px 0}.footer{margin-top:24px;border-top:1px solid #d8e4e0;padding-top:8px;font-size:9px;color:#66756f}</style></head><body>'
            .'<div class="top"><table style="width:100%"><tr><td>'.$logo.'<div class="brand">'.$e($company['company_name'] ?? 'VNV Events').'</div><div class="muted">'.$e($companyAddress).'<br>'.$e($company['phone'] ?? '').' · '.$e($company['email'] ?? '').'</div></td><td><div class="title">INVOICE</div><div style="text-align:right"><strong>'.$e($invoice->invoice_number).'</strong><br>Version '.$versionNumber.' · '.date('F j, Y').'<br>Status: '.$e($statusLabel).'</div></td></tr></table></div>'
            .'<table class="grid"><tr><td><div class="box"><strong>BILL TO</strong><br>'.$e($invoice->billing_name).'<br>'.$e($invoice->billing_email).'<br>'.$e($invoice->billing_phone).'<br>'.$e($invoice->billing_address).'</div></td><td><div class="box"><strong>EVENT / ORDER</strong><br>Order #'.$e($order->id).$suborderLabel.'<br>'.$e(date('F j, Y', strtotime((string)$order->event_date))).' · '.$e(substr((string)$order->start_time, 0, 5)).'–'.$e(substr((string)$order->end_time, 0, 5)).'<br>'.$e($order->address).'<br>Reference: '.$e($invoice->internal_reference ?: '—').'</div></td></tr></table>'
            .'<table class="lines"><thead><tr><th>Service</th><th class="center">Qty</th><th class="money">Unit price</th><th class="money">Amount</th></tr></thead><tbody>'.$lineRows.'</tbody></table>'
            .'<table class="totals"><tr><td>Subtotal</td><td class="money">$'.number_format($totals['subtotal'], 2).'</td></tr><tr><td>Discount</td><td class="money">-$'.number_format($totals['discount'], 2).'</td></tr><tr><td>'.$taxLabel.'</td><td class="money">$'.number_format($totals['tax'], 2).'</td></tr><tr class="grand"><td>Consolidated Total</td><td class="money">$'.number_format($totals['total'], 2).'</td></tr><tr><td>Paid</td><td class="money">-$'.number_format($totals['net_paid'], 2).'</td></tr><tr class="grand"><td>Balance</td><td class="money">$'.number_format($totals['balance'], 2).'</td></tr></table>'
            .$paidStamp.'<h3>Payment history</h3><table class="payments"><thead><tr><th>Date / source</th><th>Method</th><th>Processor reference</th><th class="money">Paid</th><th class="money">Refunded</th></tr></thead><tbody>'.$paymentRows.'</tbody></table>'.($invoice->invoice_notes ? '<h3>Invoice notes</h3><div class="box">'.nl2br($e($invoice->invoice_notes)).'</div>' : '').'<div class="footer">This consolidated invoice includes the main order and all active suborders, with one summarized total. It does not charge a card or create a new payment.</div></body></html>';
        $options = new Options(); $options->set('isHtml5ParserEnabled', true); $options->set('isRemoteEnabled', true); $options->set('defaultFont', 'Arial');
        $pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('letter'); $pdf->render(); return $pdf->output();
    }
}
