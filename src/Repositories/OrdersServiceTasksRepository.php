<?php

namespace App\Repositories;

class OrdersServiceTasksRepository extends BaseRepository
{

    public function __construct() {
        $this->db = new Connection();
        $this->table = "orders_service_tasks";
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
}
