<?php

use App\Repositories\OrdersPaymentsRepository;
use App\Services\TranslationService;
use App\Utils\LocationUtils;

$paymentId = $_GET['payment_id'] ?? null;
$token = $_GET['token'] ?? null;

if (!$paymentId || !$token) {
    LocationUtils::redirectInternal("/404");
}

$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded["order_id"])) {
    LocationUtils::redirectInternal("/404");
}

$paymentsRepo = new OrdersPaymentsRepository();
$payment = $paymentsRepo->getOne(["id" => $paymentId]);

if (!$payment || $payment->id_order != $decoded["order_id"]) {
    LocationUtils::redirectInternal("/404");
}

if (empty($payment->receipt_pdf)) {
    LocationUtils::redirectInternal("/404");
}

$pdfPath = $payment->receipt_pdf;
$isUrl = filter_var($pdfPath, FILTER_VALIDATE_URL) !== false;

if ($isUrl) {
    try {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
            'http' => [
                'ignore_errors' => true,
                'timeout' => 30
            ]
        ]);
        
        $pdfContent = @file_get_contents($pdfPath, false, $context);
        
        if ($pdfContent === false || empty($pdfContent)) {
            throw new Exception(TranslationService::trans('planner_hub.failed_download_pdf_url'));
        }
        
        $fileSize = strlen($pdfContent);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="tip_receipt_' . $payment->id . '.pdf"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdfContent;
        exit;
        
    } catch (\Exception $e) {
        LocationUtils::redirectInternal("/404");
    }
} else {
    if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
        LocationUtils::redirectInternal("/404");
    }
    
    $fileSize = filesize($pdfPath);
    if ($fileSize === false || $fileSize == 0) {
        LocationUtils::redirectInternal("/404");
    }
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="tip_receipt_' . $payment->id . '.pdf"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile($pdfPath);
    exit;
}



