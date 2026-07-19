<?php

namespace App\Services;

use App\Repositories\ReminderLogsRepository;
use App\Repositories\NotificationsRepository;
use App\Services\EmailService;
use App\Repositories\UserRepository;
use Exception;

class ReminderService
{
    private ReminderLogsRepository $reminderLogsRepo;
    private NotificationsRepository $notificationsRepo;
    private EmailService $emailService;
    private UserRepository $userRepo;

    public function __construct()
    {
        $this->reminderLogsRepo = new ReminderLogsRepository();
        $this->notificationsRepo = new NotificationsRepository();
        $this->emailService = new EmailService();
        $this->userRepo = new UserRepository();
    }

    public function sendContractReminders(int $userId, array $orderIds): array
    {
        $results = [];
        $orders = $this->reminderLogsRepo->getOrdersNeedingContractReminder($userId);
        
        foreach ($orders as $order) {
            if (in_array($order->id, $orderIds)) {
                $result = $this->sendContractReminder($order);
                $results[] = $result;
            }
        }
        
        return $results;
    }

    public function sendPaymentReminders(int $userId, array $orderIds): array
    {
        $results = [];
        
        // Obtener órdenes principales
        $orders = $this->reminderLogsRepo->getOrdersNeedingPaymentReminder($userId);
        foreach ($orders as $order) {
            if (in_array($order->id, $orderIds)) {
                $result = $this->sendPaymentReminder($order, 'order');
                $results[] = $result;
            }
        }
        
        // Obtener subórdenes
        $suborders = $this->reminderLogsRepo->getSubordersNeedingPaymentReminder($userId);
        foreach ($suborders as $suborder) {
            if (in_array($suborder->id, $orderIds)) {
                $result = $this->sendPaymentReminder($suborder, 'suborder');
                $results[] = $result;
            }
        }
        
        return $results;
    }

    private function sendContractReminder($order): array
    {
        try {
            if ($this->reminderLogsRepo->hasReminderSentToday($order->id, $order->id_client, 'contract_signature')) {
                return [
                    'success' => false,
                    'message' => "Contract reminder already sent today for order #{$order->id}",
                    'order_id' => $order->id,
                    'client_email' => $order->email
                ];
            }

            $contractToken = $this->generateContractToken($order->id, $order->id_client);
            $subject = "📝 Contract Signature Required - VNV-Events - Order #VNV341{$order->id}";
            $body = $this->getContractReminderEmailBody($order, $contractToken);
            
            $emailSent = $this->emailService->sendSimpleEmail(
                $order->email,
                $subject,
                $body,
                true
            );
            
            if ($emailSent) {
                $this->createReminderNotification($order->id_user, $order->id, $order->id_client, 'contract_signature');
                $this->createClientReminderNotification($order->id_client, $order->id, 'contract_signature');
                $this->reminderLogsRepo->logReminderSent($order->id, $order->id_client, 'contract_signature');
                
                return [
                    'success' => true,
                    'message' => "Contract reminder sent to {$order->email}",
                    'order_id' => $order->id,
                    'client_email' => $order->email
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Failed to send contract reminder to {$order->email}",
                    'order_id' => $order->id,
                    'client_email' => $order->email
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error sending contract reminder: " . $e->getMessage(),
                'order_id' => $order->id,
                'client_email' => $order->email
            ];
        }
    }

    private function sendPaymentReminder($order, string $type): array
    {
        try {
            $reminderType = $order->reminder_type ?? 'first_payment';
            
            if ($this->reminderLogsRepo->hasReminderSentToday($order->id, $order->id_client, $reminderType)) {
                return [
                    'success' => false,
                    'message' => "Payment reminder already sent today for {$type} #{$order->id}",
                    'order_id' => $order->id,
                    'client_email' => $order->email
                ];
            }

            $paymentToken = $this->generatePaymentToken($order->id, $order->id_client);
            $subject = "💳 Payment Reminder - VNV-Events - Order #VNV341{$order->id}";
            $body = $this->getPaymentReminderEmailBody($order, $paymentToken, $reminderType);
            
            $emailSent = $this->emailService->sendSimpleEmail(
                $order->email,
                $subject,
                $body,
                true
            );
            
            if ($emailSent) {
                $this->createReminderNotification($order->id_user, $order->id, $order->id_client, $reminderType);
                $this->createClientReminderNotification($order->id_client, $order->id, $reminderType);
                $this->reminderLogsRepo->logReminderSent($order->id, $order->id_client, $reminderType);
                
                return [
                    'success' => true,
                    'message' => "Payment reminder sent to {$order->email}",
                    'order_id' => $order->id,
                    'client_email' => $order->email,
                    'type' => $type
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Failed to send payment reminder to {$order->email}",
                    'order_id' => $order->id,
                    'client_email' => $order->email,
                    'type' => $type
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error sending payment reminder: " . $e->getMessage(),
                'order_id' => $order->id,
                'client_email' => $order->email,
                'type' => $type
            ];
        }
    }

    private function generateContractToken(int $orderId, int $clientId): string
    {
        $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
        $payload = [
            "order_id" => $orderId,
            "user_id" => $clientId,
            "exp" => time() + (86400 * 30)
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode($payload), $secret);
        return base64_encode(json_encode($payload));
    }

    private function generatePaymentToken(int $orderId, int $clientId): string
    {
        return $this->generateContractToken($orderId, $clientId);
    }

    private function getContractReminderEmailBody($order, string $token): string
    {
        $baseUrl = rtrim($_ENV['APP_URL'] ?? 'https://vnvevents.com', '/') . '/';
        $contractUrl = $baseUrl . "/order-access?token=" . $token;
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #333;'>📝 Contract Signature Required</h2>
            
            <p>Dear {$order->name} {$order->lastname},</p>
            
            <p>We hope this email finds you well. We wanted to remind you about your upcoming event order <strong>#VNV341{$order->id}</strong>.</p>
            
            <p><strong>Event Details:</strong></p>
            <ul>
                <li><strong>Date:</strong> " . date('l, F j, Y', strtotime($order->event_date)) . "</li>
                <li><strong>Time:</strong> " . date('g:i A', strtotime($order->start_time)) . " - " . date('g:i A', strtotime($order->end_time)) . "</li>
                <li><strong>Location:</strong> {$order->address}</li>
            </ul>
            
            <p>Your contract is ready for signature. Please review and sign the contract at your earliest convenience to proceed with your event planning.</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$contractUrl}' style='background-color: #007bff; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>
                    📝 Sign Contract Now
                </a>
            </div>
            
            <p>If you have any questions or need assistance, please don't hesitate to contact us.</p>
            
            <p>Best regards,<br>
            <strong>VNV-Events Team</strong></p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666;'>
                © " . date("Y") . " VNV-Events | <a href='{$baseUrl}'>{$baseUrl}</a><br>
                Planning made easier ✨
            </div>
        </div>";
    }

    private function getPaymentReminderEmailBody($order, string $token, string $reminderType): string
    {
        $baseUrl = rtrim($_ENV['APP_URL'] ?? 'https://vnvevents.com', '/') . '/';
        $paymentUrl = $baseUrl . "/order-access?token=" . $token;
        
        $paymentText = $reminderType === 'first_payment' ? 'first payment' : 'second payment';
        $paymentTitle = $reminderType === 'first_payment' ? 'First Payment Due' : 'Second Payment Due';
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #333;'>💳 {$paymentTitle}</h2>
            
            <p>Dear {$order->name} {$order->lastname},</p>
            
            <p>Thank you for choosing VNV-Events for your event planning needs.</p>
            
            <p><strong>Event Details:</strong></p>
            <ul>
                <li><strong>Order #:</strong> VNV341{$order->id}</li>
                <li><strong>Date:</strong> " . date('l, F j, Y', strtotime($order->event_date)) . "</li>
                <li><strong>Time:</strong> " . date('g:i A', strtotime($order->start_time)) . " - " . date('g:i A', strtotime($order->end_time)) . "</li>
                <li><strong>Location:</strong> {$order->address}</li>
            </ul>
            
            <p>Your {$paymentText} is now due. Please complete your payment to secure your event booking.</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$paymentUrl}' style='background-color: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>
                    💳 Make Payment Now
                </a>
            </div>
            
            <p>You can make your payment through the secure payment link above. If you have any questions about the payment process, please contact us.</p>
            
            <p>Best regards,<br>
            <strong>VNV-Events Team</strong></p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666;'>
                © " . date("Y") . " VNV-Events | <a href='{$baseUrl}'>{$baseUrl}</a><br>
                Planning made easier ✨
            </div>
        </div>";
    }

    private function createReminderNotification(int $userId, int $orderId, int $clientId, string $reminderType): void
    {
        $message = match($reminderType) {
            'contract_signature' => "Contract reminder sent for order #{$orderId}",
            'first_payment' => "First payment reminder sent for order #{$orderId}",
            'second_payment' => "Second payment reminder sent for order #{$orderId}",
            default => "Reminder sent for order #{$orderId}"
        };
        
        $link = "/vnv-venue/panel/planner-hub/management/orders/orders/reminders";
        
        $sql = "INSERT INTO notifications (id_user, mensaje, link, leido) VALUES (:id_user, :mensaje, :link, :leido)";
        $this->notificationsRepo->db->query($sql);
        $this->notificationsRepo->db->bind(":id_user", $userId);
        $this->notificationsRepo->db->bind(":mensaje", $message);
        $this->notificationsRepo->db->bind(":link", $link);
        $this->notificationsRepo->db->bind(":leido", 0);
        $this->notificationsRepo->db->execute();
    }

    private function createClientReminderNotification(int $clientId, int $orderId, string $reminderType): void
    {
        $message = match($reminderType) {
            'contract_signature' => "Contract signature reminder sent for order #{$orderId}",
            'first_payment' => "First payment reminder sent for order #{$orderId}",
            'second_payment' => "Second payment reminder sent for order #{$orderId}",
            default => "Reminder sent for order #{$orderId}"
        };
        
        $link = "/vnv-venue/order-access?token=" . $this->generateContractToken($orderId, $clientId);
        
        $sql = "INSERT INTO notifications (id_user, mensaje, link, leido) VALUES (:id_user, :mensaje, :link, :leido)";
        $this->notificationsRepo->db->query($sql);
        $this->notificationsRepo->db->bind(":id_user", $clientId);
        $this->notificationsRepo->db->bind(":mensaje", $message);
        $this->notificationsRepo->db->bind(":link", $link);
        $this->notificationsRepo->db->bind(":leido", 0);
        $this->notificationsRepo->db->execute();
    }
}
