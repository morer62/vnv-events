<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\ReminderLogsRepository;
use App\Services\ReminderService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(callback: function () {
    $user = LoginService::getSession();
    $reminderRepo = new ReminderLogsRepository();
    
    // Obtener órdenes que necesitan recordatorio de contrato
    $contractOrders = $reminderRepo->getOrdersNeedingContractReminder($user->getId());
    
    // Obtener órdenes que necesitan recordatorio de pago
    $paymentOrders = $reminderRepo->getOrdersNeedingPaymentReminder($user->getId());
    
    // Obtener subórdenes que necesitan recordatorio de pago
    $paymentSuborders = $reminderRepo->getSubordersNeedingPaymentReminder($user->getId());
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "contractOrders" => $contractOrders,
        "paymentOrders" => $paymentOrders,
        "paymentSuborders" => $paymentSuborders,
        "user" => $user
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $reminderService = new ReminderService();
    
    if (isset($_POST['send_contract_reminders'])) {
        $orderIds = $_POST['contract_order_ids'] ?? [];
        $orderIds = array_map('intval', $orderIds);
        
        if (empty($orderIds)) {
            MessageUtil::setMessage("Please select at least one order to send contract reminders.");
            LocationUtils::reload();
            return;
        }
        
        $results = $reminderService->sendContractReminders($session->getId(), $orderIds);
        
        $successCount = 0;
        $errorCount = 0;
        $alreadySentCount = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                if (strpos($result['message'], 'already sent today') !== false) {
                    $alreadySentCount++;
                } else {
                    $errorCount++;
                }
            }
        }
        
        $message = "Contract reminders sent: {$successCount} successful";
        if ($alreadySentCount > 0) {
            $message .= ", {$alreadySentCount} already sent today";
        }
        if ($errorCount > 0) {
            $message .= ", {$errorCount} failed";
        }
        
        MessageUtil::setMessage($message);
        LocationUtils::reload();
        return;
    }
    
    if (isset($_POST['send_payment_reminders'])) {
        $orderIds = $_POST['payment_order_ids'] ?? [];
        $orderIds = array_map('intval', $orderIds);
        
        if (empty($orderIds)) {
            MessageUtil::setMessage("Please select at least one order to send payment reminders.");
            LocationUtils::reload();
            return;
        }
        
        $results = $reminderService->sendPaymentReminders($session->getId(), $orderIds);
        
        $successCount = 0;
        $errorCount = 0;
        $alreadySentCount = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                if (strpos($result['message'], 'already sent today') !== false) {
                    $alreadySentCount++;
                } else {
                    $errorCount++;
                }
            }
        }
        
        $message = "Payment reminders sent: {$successCount} successful";
        if ($alreadySentCount > 0) {
            $message .= ", {$alreadySentCount} already sent today";
        }
        if ($errorCount > 0) {
            $message .= ", {$errorCount} failed";
        }
        
        MessageUtil::setMessage($message);
        LocationUtils::reload();
        return;
    }
});

$router->run();
