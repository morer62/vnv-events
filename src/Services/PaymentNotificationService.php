<?php

namespace App\Services;

use App\Repositories\NotificationsRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Services\OrderCalculatorService;
use App\Services\EmailService;

class PaymentNotificationService
{
    /**
     * Genera notificaciones de pagos para una orden específica
     */
    public static function generatePaymentNotifications(int $orderId): void
    {
        $notificationsRepo = new NotificationsRepository();
        $paymentRepo = new OrdersPaymentsRepository();
        $orderRepo = new OrdersRepository();
        
        // Obtener la orden
        $order = $orderRepo->getOne(["id" => $orderId]);
        if (!$order) {
            return;
        }
        
        // Obtener pagos de la orden
        $payments = $paymentRepo->getAllBy(["id_order" => $orderId]);
        
        // Calcular montos
        $amounts = OrderCalculatorService::calculateTotal($order);
        $firstPercent = $order->payment_split_percent_1 ?? 50;
        $secondPercent = $order->payment_split_percent_2 ?? 50;
        
        $firstPayment = round($amounts["total"] * $firstPercent / 100, 2);
        $secondPayment = round($amounts["total"] * $secondPercent / 100, 2);
        
        // Verificar pagos existentes
        $paymentStatus = 'pending_first';
        if (count($payments) > 0) {
            if ($order->payment_split_type == 2) {
                $paymentStatus = count($payments) === 1 ? 'pending_second' : 'complete';
            } elseif ($order->payment_split_type == 1) {
                $paymentStatus = 'complete';
            }
        }
        
        // Verificar si ya se generaron notificaciones de pagos para esta orden
        $existingPaymentNotifications = $notificationsRepo->getAllBy([
            'id_user' => $order->id_owner
        ]);
        
        $hasFirstPaymentNotification = false;
        $hasCompletePaymentNotification = false;
        
        foreach ($existingPaymentNotifications as $notification) {
            // Verificar si es una notificación de pago por el mensaje
            if (strpos($notification->mensaje, 'First Payment Received') !== false && 
                strpos($notification->mensaje, 'VNV341' . $orderId) !== false) {
                $hasFirstPaymentNotification = true;
            }
            if (strpos($notification->mensaje, 'Payment Complete') !== false && 
                strpos($notification->mensaje, 'VNV341' . $orderId) !== false) {
                $hasCompletePaymentNotification = true;
            }
        }
        
        // Generar notificación para primer pago
        if (count($payments) === 1 && $order->payment_split_type == 2 && !$hasFirstPaymentNotification) {
            // Notificación para el propietario
            $notificationsRepo->add([
                "id_user" => $order->id_owner,
                "mensaje" => "💰 First Payment Received - Payment #1 received for order #VNV341" . $orderId,
                "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/management/orders/orders",
                "leido" => 0
            ]);
            
            // Notificación para el cliente
            $notificationsRepo->add([
                "id_user" => $order->id_client,
                "mensaje" => "✅ Payment Confirmed - Your first payment for order #VNV341" . $orderId . " has been received successfully.",
                "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/orders/orders",
                "leido" => 0
            ]);
            
            NotificationService::sendToUsers(
                [$order->id_owner],
                '💰 First Payment Received',
                'Payment #1 received for order #VNV341' . $orderId
            );
            
            // Enviar email de confirmación de pago al cliente
            self::sendPaymentConfirmationEmail($order, 'first', $firstPayment);
        }
        
        // Generar notificación para pago completo
        if ($paymentStatus === 'complete' && !$hasCompletePaymentNotification) {
            // Notificación para el propietario
            $notificationsRepo->add([
                "id_user" => $order->id_owner,
                "mensaje" => "🎉 Payment Complete - All payments received for order #VNV341" . $orderId,
                "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/management/orders/orders",
                "leido" => 0
            ]);
            
            // Notificación para el cliente
            $notificationsRepo->add([
                "id_user" => $order->id_client,
                "mensaje" => "🎉 Payment Complete - All payments for order #VNV341" . $orderId . " have been received successfully.",
                "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/orders/orders",
                "leido" => 0
            ]);
            
            NotificationService::sendToUsers(
                [$order->id_owner],
                '🎉 Payment Complete',
                'All payments received for order #VNV341' . $orderId
            );
            
            // Enviar email de confirmación de pago completo al cliente
            self::sendPaymentConfirmationEmail($order, 'complete', $amounts["total"]);
        }
    }
    
    /**
     * Genera un token para acceso a la orden
     */
    private static function generateOrderToken(int $orderId, int $userId): string
    {
        $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
        $payload = [
            "order_id" => $orderId,
            "user_id" => $userId,
            "exp" => time() + 60 * 60 * 24 * 30, // 30 días
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode([
            "order_id" => $payload["order_id"],
            "user_id" => $payload["user_id"],
            "exp" => $payload["exp"]
        ]), $secret);
        return urlencode(base64_encode(json_encode($payload)));
    }
    
    /**
     * Envía email de confirmación de pago al cliente
     */
    private static function sendPaymentConfirmationEmail($order, string $paymentType, float $amount): void
    {
        try {
            $userRepo = new UserRepository();
            $client = $userRepo->getOne(["id" => $order->id_client]);
            
            if (!$client || !$client->email) {
                error_log("Client email not found for payment confirmation - Order ID: " . $order->id);
                return;
            }
            
            $emailService = new EmailService();
            
            // Determinar el tipo de pago y el mensaje
            $subject = "";
            $templateData = [];
            
            if ($paymentType === 'first') {
                $subject = "✅ First Payment Confirmed - Order #VNV341" . $order->id;
                $templateData = [
                    'orderId' => $order->id,
                    'paymentType' => 'First Payment',
                    'amount' => $amount,
                    'eventDate' => date("F j, Y", strtotime($order->event_date)),
                    'eventTime' => date("g:i A", strtotime($order->start_time)) . ' to ' . date("g:i A", strtotime($order->end_time)),
                    'location' => $order->address,
                    'orderUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/panel/planner-hub/orders/orders",
                    'remainingMessage' => 'Your second payment will be due closer to the event date.'
                ];
            } else {
                $subject = "🎉 Payment Complete - Order #VNV341" . $order->id;
                $templateData = [
                    'orderId' => $order->id,
                    'paymentType' => 'Full Payment',
                    'amount' => $amount,
                    'eventDate' => date("F j, Y", strtotime($order->event_date)),
                    'eventTime' => date("g:i A", strtotime($order->start_time)) . ' to ' . date("g:i A", strtotime($order->end_time)),
                    'location' => $order->address,
                    'orderUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/panel/planner-hub/orders/orders",
                    'remainingMessage' => 'Your order is now fully paid and confirmed!'
                ];
            }
            
            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/payment_confirmation.php");
            
            $emailResult = $emailService->sendTemplateEmail(
                $client->email,
                $subject,
                $templatePath,
                $templateData
            );
            
            if ($emailResult) {
                error_log("✅ Payment confirmation email sent successfully to: " . $client->email);
            } else {
                error_log("❌ Failed to send payment confirmation email to: " . $client->email . " - Debug: " . $emailService->getDebugInfo());
            }
            
        } catch (\Exception $e) {
            error_log("Error sending payment confirmation email: " . $e->getMessage());
        }
    }
}
