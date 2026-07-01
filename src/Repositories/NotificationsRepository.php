<?php

namespace App\Repositories;

use App\Repositories\Connection;
use Exception;

class NotificationsRepository extends BaseRepository
{
    protected string $table = "notifications";

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getByUser(int $userId): array
    {
        $notifications = $this->getAllBy(['id_user' => $userId]);
        
        // Ordenar por timestamp descendente (más recientes primero)
        usort($notifications, function($a, $b) {
            return strtotime($b->timestamp) - strtotime($a->timestamp);
        });
        
        return $notifications;
    }

    public function getUnreadByUser(int $userId): array
    {
        $notifications = $this->getAllBy(['id_user' => $userId, 'leido' => 0]);
        
        // Ordenar por timestamp descendente (más recientes primero)
        usort($notifications, function($a, $b) {
            return strtotime($b->timestamp) - strtotime($a->timestamp);
        });
        
        return $notifications;
    }

    public function getByUserAndId(int $userId, int $notificationId): ?object
    {
        $this->db->query("SELECT * FROM notifications WHERE id = :id AND id_user = :id_user LIMIT 1");
        $this->db->bind(":id", $notificationId);
        $this->db->bind(":id_user", $userId);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    public function getMobileBroadcastsByUser(int $userId): array
    {
        $this->db->query(
            "SELECT *
             FROM notifications
             WHERE id_user = :id_user
               AND link LIKE 'mobile-app-broadcast://%'
             ORDER BY timestamp DESC"
        );
        $this->db->bind(":id_user", $userId);
        return $this->db->fetchAll();
    }

    public function getMobileBroadcastByUserAndId(int $userId, int $notificationId): ?object
    {
        $this->db->query(
            "SELECT *
             FROM notifications
             WHERE id = :id
               AND id_user = :id_user
               AND link LIKE 'mobile-app-broadcast://%'
             LIMIT 1"
        );
        $this->db->bind(":id", $notificationId);
        $this->db->bind(":id_user", $userId);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    public function markAsRead(int $notificationId): bool
    {
        error_log("DEBUG: NotificationsRepository::markAsRead() - ID: " . $notificationId);
        
        $result = $this->update(['leido' => 1], ['id' => $notificationId]);
        
        error_log("DEBUG: NotificationsRepository::markAsRead() - Resultado: " . ($result ? 'TRUE' : 'FALSE'));
        
        return $result;
    }

    public function markAllAsRead(int $userId): bool
    {
        try {
            $sql = "UPDATE notifications SET leido = 1 WHERE id_user = :user_id AND leido = 0";
            $this->db->query($sql);
            $this->db->bind(":user_id", $userId);
            $this->db->execute();
            
            return true;
        } catch (Exception $e) {
            error_log("Error marking all notifications as read: " . $e->getMessage());
            return false;
        }
    }

    public function getUnreadCount(int $userId): int
    {
        $notifications = $this->getAllBy(['id_user' => $userId, 'leido' => 0]);
        return count($notifications);
    }
    
    public function getAllNotifications(): array
    {
        $notifications = $this->getAllBy([]);
        
        // Ordenar por timestamp descendente (más recientes primero)
        usort($notifications, function($a, $b) {
            return strtotime($b->timestamp) - strtotime($a->timestamp);
        });
        
        return $notifications;
    }
}
