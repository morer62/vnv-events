<?php

namespace App\Repositories;

class OrdersTeamTasksRepository extends BaseRepository
{

    public function __construct() {
        $this->db = new Connection();
        $this->table = "orders_team_tasks";
    }

    public function getAllSortedByName(): array
    {
        $this->db->query("SELECT * FROM `$this->table` ORDER BY name ASC");
        return $this->db->fetchAll();
    }

    public function getOneWithoutOwnershipCheck(array $conditions): ?object
    {
        $whereClauses = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            $whereClauses[] = "`$key` = ?";
            $params[] = $value;
        }

        $whereSQL = implode(" AND ", $whereClauses);
        $query = "SELECT * FROM `$this->table` WHERE $whereSQL LIMIT 1";

        $this->db->query($query);
        
        // Bind parameters
        foreach ($params as $index => $value) {
            $this->db->bind($index + 1, $value);
        }
        
        $result = $this->db->fetchOne();
        
        return $result ?: null;
    }

    public function getAllWithoutOwner(array $conditions): array
    {
        $whereClauses = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            $whereClauses[] = "`$key` = ?";
            $params[] = $value;
        }

        $whereSQL = implode(" AND ", $whereClauses);
        $query = "SELECT * FROM `$this->table` WHERE $whereSQL";

        $this->db->query($query);
        
        // Bind parameters
        foreach ($params as $index => $value) {
            $this->db->bind($index + 1, $value);
        }
        
        return $this->db->fetchAll();
    }

    public function getForUserAndOwnerDetailed(int $userId, int $ownerId): array
    {
        $this->db->query("
            SELECT t.*,
                   o.id AS related_order_id,
                   o.event_date,
                   o.start_time AS order_start_time,
                   o.end_time AS order_end_time,
                   o.address AS order_address,
                   o.id_client,
                   osn.install_time AS task_setup_time,
                   osn.start_time AS task_activity_start_time,
                   osn.execution_time AS task_activity_end_time,
                   osn.breakdown_time AS task_breakdown_time,
                   s.name AS assigned_service_name,
                   TRIM(CONCAT(COALESCE(c.name, ''), ' ', COALESCE(c.lastname, ''))) AS contact_name,
                   c.email AS contact_email
            FROM {$this->table} t
            LEFT JOIN orders o ON o.id = t.id_order
            LEFT JOIN orders_services_notes osn ON osn.id_order = o.id
                AND osn.id_service = t.id_service
                AND (osn.id_suborder IS NULL OR osn.id_suborder = 0)
            LEFT JOIN orders_services s ON s.id = t.id_service
            LEFT JOIN users c ON c.id = o.id_client
            WHERE t.id_user = :user AND t.id_owner = :owner
              AND o.event_date >= CURDATE()
            ORDER BY o.event_date ASC, COALESCE(osn.install_time, o.start_time) ASC, t.is_done ASC, t.created_at DESC, t.id DESC
        ");
        $this->db->bind(':user', $userId);
        $this->db->bind(':owner', $ownerId);
        return $this->db->fetchAll();
    }

    public function getAssigneesByOrders(int $ownerId, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($ownerId <= 0 || empty($orderIds)) {
            return [];
        }

        $placeholders = [];
        foreach ($orderIds as $index => $orderId) {
            $placeholders[] = ':order' . $index;
        }
        $placeholdersSql = implode(',', $placeholders);

        $this->db->query("
            SELECT DISTINCT t.id_order, u.id, u.name, u.lastname, u.email, u.level, u.allow_chat_with_clients
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            WHERE t.id_owner = :owner
              AND t.id_order IN ({$placeholdersSql})
              AND t.id_user IS NOT NULL
            ORDER BY u.name ASC, u.lastname ASC, u.email ASC
        ");
        $this->db->bind(':owner', $ownerId);
        foreach ($orderIds as $index => $orderId) {
            $this->db->bind(':order' . $index, $orderId);
        }

        $rows = $this->db->fetchAll();
        $contactsByOrder = [];
        foreach ($rows as $row) {
            $contactsByOrder[(int)$row->id_order][] = $row;
        }

        return $contactsByOrder;
    }

    public function getAssigneesForClientOrders(int $clientId, array $ownerIds): array
    {
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds))));
        if ($clientId <= 0 || empty($ownerIds)) {
            return [];
        }

        $placeholders = [];
        foreach ($ownerIds as $index => $ownerId) {
            $placeholders[] = ':owner' . $index;
        }
        $placeholdersSql = implode(',', $placeholders);

        $this->db->query("
            SELECT DISTINCT u.id, u.name, u.lastname, u.email, u.level, u.id_owner, u.allow_chat_with_clients
            FROM {$this->table} t
            INNER JOIN orders o ON o.id = t.id_order
            INNER JOIN users u ON u.id = t.id_user
            WHERE o.id_client = :client
              AND t.id_owner IN ({$placeholdersSql})
              AND u.level = 4
              AND u.is_active = 1
            ORDER BY u.name ASC, u.lastname ASC, u.email ASC
        ");
        $this->db->bind(':client', $clientId);
        foreach ($ownerIds as $index => $ownerId) {
            $this->db->bind(':owner' . $index, $ownerId);
        }

        return $this->db->fetchAll();
    }

    public function getClientsForAssignee(int $ownerId, int $userId): array
    {
        if ($ownerId <= 0 || $userId <= 0) {
            return [];
        }

        $this->db->query("
            SELECT DISTINCT c.id, c.name, c.lastname, c.email, c.phone, c.phone_code, c.level, c.is_active
            FROM {$this->table} t
            INNER JOIN orders o ON o.id = t.id_order
            INNER JOIN users c ON c.id = o.id_client
            WHERE t.id_owner = :owner
              AND t.id_user = :user
              AND c.level = 5
              AND c.is_active = 1
            ORDER BY c.name ASC, c.lastname ASC, c.email ASC
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':user', $userId);

        return $this->db->fetchAll();
    }

    public function assigneeCanChatWithOrderClient(int $ownerId, int $userId, int $clientId): bool
    {
        if ($ownerId <= 0 || $userId <= 0 || $clientId <= 0) {
            return false;
        }

        $this->db->query("
            SELECT t.id
            FROM {$this->table} t
            INNER JOIN orders o ON o.id = t.id_order
            WHERE t.id_owner = :owner
              AND t.id_user = :user
              AND o.id_client = :client
            LIMIT 1
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':user', $userId);
        $this->db->bind(':client', $clientId);

        return (bool)$this->db->fetchOne();
    }

}

