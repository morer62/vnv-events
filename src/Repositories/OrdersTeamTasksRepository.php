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

}
