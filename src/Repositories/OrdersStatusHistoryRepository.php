<?php

namespace App\Repositories;

use App\Repositories\BaseRepository; 

class OrdersStatusHistoryRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = "orders_status_history";
    }

    public function getAllByOrderSorted(array $criteriaVals, string $orderBy): array
    {
        $columnsSQL = "*";
        $keys = array_keys($criteriaVals);
        $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));
        $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where ORDER BY $orderBy";

        $this->db->query($query);

        foreach ($criteriaVals as $key => $val) {
            $this->db->bind(":$key", $val);
        }

        return $this->db->fetchAll();
    }

 
}
