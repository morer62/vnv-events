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

}
