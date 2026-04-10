<?php

namespace App\Repositories;

use App\Repositories\Connection;
use Exception;

class ReminderLogsRepository extends BaseRepository
{
    protected string $table = "reminder_logs";

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function hasReminderSentToday(int $orderId, int $clientId, string $reminderType): bool
    {
        $today = date('Y-m-d');
        $logs = $this->getAllBy([
            'order_id' => $orderId,
            'client_id' => $clientId,
            'reminder_type' => $reminderType,
            'sent_date' => $today
        ]);
        
        return count($logs) > 0;
    }

    public function logReminderSent(int $orderId, int $clientId, string $reminderType): bool
    {
        try {
            $today = date('Y-m-d');
            $data = [
                'order_id' => $orderId,
                'client_id' => $clientId,
                'reminder_type' => $reminderType,
                'sent_date' => $today,
                'email_sent' => 1,
                'notification_created' => 1
            ];
            
            $sql = "INSERT INTO reminder_logs (order_id, client_id, reminder_type, sent_date, email_sent, notification_created) 
                    VALUES (:order_id, :client_id, :reminder_type, :sent_date, :email_sent, :notification_created)";
            $this->db->query($sql);
            $this->db->bind(":order_id", $data['order_id']);
            $this->db->bind(":client_id", $data['client_id']);
            $this->db->bind(":reminder_type", $data['reminder_type']);
            $this->db->bind(":sent_date", $data['sent_date']);
            $this->db->bind(":email_sent", $data['email_sent']);
            $this->db->bind(":notification_created", $data['notification_created']);
            $this->db->execute();
            
            return $this->db->lastId() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getOrdersNeedingContractReminder(int $userId): array
    {
        $sql = "SELECT DISTINCT o.*, c.name, c.lastname, c.email 
                FROM orders o 
                LEFT JOIN users c ON c.id = o.id_client 
                WHERE o.id_user = :user_id 
                AND o.is_archived = 0 
                AND o.event_date >= CURDATE() 
                AND o.status_workflow = 'INVOICE_DRAFT'
                AND NOT EXISTS (
                    SELECT 1 FROM reminder_logs rl 
                    WHERE rl.order_id = o.id 
                    AND rl.client_id = o.id_client 
                    AND rl.reminder_type = 'contract_signature' 
                    AND rl.sent_date = CURDATE()
                )";
        
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        return $this->db->fetchAll();
    }

    public function getOrdersNeedingPaymentReminder(int $userId): array
    {
        $sql = "SELECT DISTINCT o.*, c.name, c.lastname, c.email,
                       CASE 
                           WHEN o.status_workflow = 'INVOICE_READY' THEN 'first_payment'
                           WHEN o.status_workflow = 'INVOICE_PARTIAL' THEN 'second_payment'
                           ELSE 'first_payment'
                       END as reminder_type
                FROM orders o 
                LEFT JOIN users c ON c.id = o.id_client 
                WHERE o.id_user = :user_id 
                AND o.is_archived = 0 
                AND o.event_date >= CURDATE() 
                AND o.status_workflow IN ('INVOICE_READY', 'INVOICE_PARTIAL')
                AND NOT EXISTS (
                    SELECT 1 FROM reminder_logs rl 
                    WHERE rl.order_id = o.id 
                    AND rl.client_id = o.id_client 
                    AND rl.reminder_type = CASE 
                        WHEN o.status_workflow = 'INVOICE_READY' THEN 'first_payment'
                        WHEN o.status_workflow = 'INVOICE_PARTIAL' THEN 'second_payment'
                        ELSE 'first_payment'
                    END
                    AND rl.sent_date = CURDATE()
                )";
        
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        return $this->db->fetchAll();
    }

    public function getSubordersNeedingPaymentReminder(int $userId): array
    {
        $sql = "SELECT DISTINCT os.*, o.id_client, o.id_user, c.name, c.lastname, c.email,
                       CASE 
                           WHEN os.status_workflow = 'INVOICE_READY' THEN 'first_payment'
                           WHEN os.status_workflow = 'INVOICE_PARTIAL' THEN 'second_payment'
                           ELSE 'first_payment'
                       END as reminder_type
                FROM orders_suborder os
                LEFT JOIN orders o ON o.id = os.id_order
                LEFT JOIN users c ON c.id = o.id_client 
                WHERE o.id_user = :user_id 
                AND os.is_archived = 0 
                AND o.is_archived = 0
                AND o.event_date >= CURDATE() 
                AND os.status_workflow IN ('INVOICE_READY', 'INVOICE_PARTIAL')
                AND NOT EXISTS (
                    SELECT 1 FROM reminder_logs rl 
                    WHERE rl.order_id = os.id 
                    AND rl.client_id = o.id_client 
                    AND rl.reminder_type = CASE 
                        WHEN os.status_workflow = 'INVOICE_READY' THEN 'first_payment'
                        WHEN os.status_workflow = 'INVOICE_PARTIAL' THEN 'second_payment'
                        ELSE 'first_payment'
                    END
                    AND rl.sent_date = CURDATE()
                )";
        
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        return $this->db->fetchAll();
    }
}
