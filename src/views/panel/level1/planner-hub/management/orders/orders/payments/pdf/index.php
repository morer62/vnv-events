<?php

use App\Services\LoginService;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\Connection;
use App\Services\PaymentReceiptPdfGenerator;
use App\Utils\LocationUtils;

$user = LoginService::getSession();
if (!$user) {
    LocationUtils::redirectInternal("/404");
}

$paymentId = $_GET['payment_id'] ?? null;
$advanceId = $_GET['advance_id'] ?? null;
$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    LocationUtils::redirectInternal("/404");
}

$db = new Connection();
$paymentRepo = new OrdersPaymentsRepository();

$amountPaid = 0;
$suborderId = null;
$paymentConcept = '';

if ($paymentId) {
    $payment = $paymentRepo->getOne(["id" => $paymentId]);
    if (!$payment || $payment->id_order != $orderId) {
        LocationUtils::redirectInternal("/404");
    }
    
    $db->query("SELECT id_owner FROM orders WHERE id = :id");
    $db->bind(":id", $orderId);
    $db->execute();
    $order = $db->fetchAll()[0] ?? null;
    
    if (!$order || $order->id_owner != $user->getOwner()) {
        LocationUtils::redirectInternal("/404");
    }
    
    $amountPaid = (float)$payment->amount;
    $suborderId = !empty($payment->id_suborder) ? (int)$payment->id_suborder : null;
    
    if ($suborderId) {
        $paymentConcept = 'Suborder Payment - Sub-' . $suborderId;
    } else {
        $orderRepo = new \App\Repositories\OrdersRepository();
        $orderTotal = $orderRepo->calculateTotal($orderId);
        
        $db->query("
            SELECT COALESCE(SUM(p.amount), 0) as total_paid
            FROM orders_payments p
            WHERE p.id_order = :id AND (p.id_suborder IS NULL OR p.id_suborder = 0)
        ");
        $db->bind(":id", $orderId);
        $db->execute();
        $totalPaidResult = $db->fetchAll()[0] ?? null;
        $totalPaid = (float)($totalPaidResult->total_paid ?? 0);
        
        if ($totalPaid >= $orderTotal) {
            $paymentConcept = 'Full Payment';
        } else {
            $paymentConcept = 'Payment Installment';
        }
    }
}
elseif ($advanceId) {
    $db->query("SELECT * FROM orders_advances WHERE id = :id AND id_order = :order_id LIMIT 1");
    $db->bind(":id", (int)$advanceId);
    $db->bind(":order_id", (int)$orderId);
    $db->execute();
    $advance = $db->fetchAll()[0] ?? null;
    
    if (!$advance) {
        LocationUtils::redirectInternal("/404");
    }
    
    $db->query("SELECT id_owner FROM orders WHERE id = :id");
    $db->bind(":id", $orderId);
    $db->execute();
    $order = $db->fetchAll()[0] ?? null;
    
    if (!$order || $order->id_owner != $user->getOwner()) {
        LocationUtils::redirectInternal("/404");
    }
    
    $amountPaid = (float)$advance->amount;
    $suborderId = !empty($advance->id_suborder) && $advance->is_suborder == 1 ? (int)$advance->id_suborder : null;
    $paymentConcept = 'Advance Payment' . ($suborderId ? ' - Sub-' . $suborderId : '');
}
else {
    LocationUtils::redirectInternal("/404");
}

try {
    $pdfPath = PaymentReceiptPdfGenerator::generateAndSave(
        (int)$orderId,
        $suborderId,
        $amountPaid,
        'Square',
        $paymentConcept
    );
    
    if (!$pdfPath) {
        throw new Exception("PDF path is null or empty");
    }
    
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
                throw new Exception("Failed to download PDF from Cloudinary URL");
            }
            
            $fileSize = strlen($pdfContent);
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="payment_receipt_' . $orderId . '_' . date('Y-m-d') . '.pdf"');
            header('Content-Length: ' . $fileSize);
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            echo $pdfContent;
            exit;
            
        } catch (\Exception $e) {
            throw new Exception("Failed to download PDF from Cloudinary: " . $e->getMessage());
        }
    } else {
        if (!file_exists($pdfPath)) {
            throw new Exception("PDF file does not exist at path: " . $pdfPath);
        }
        
        if (!is_readable($pdfPath)) {
            throw new Exception("PDF file is not readable at path: " . $pdfPath);
        }
        
        $fileSize = filesize($pdfPath);
        if ($fileSize === false || $fileSize == 0) {
            throw new Exception("PDF file is empty or size cannot be determined");
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="payment_receipt_' . $orderId . '_' . date('Y-m-d') . '.pdf"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        readfile($pdfPath);
        exit;
    }
    
} catch (\Exception $e) {
    LocationUtils::redirectInternal("/404");
} catch (\Throwable $e) {
    LocationUtils::redirectInternal("/404");
}

