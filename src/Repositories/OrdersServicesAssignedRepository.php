<?php

namespace App\Repositories;

class OrdersServicesAssignedRepository extends BaseRepository
{
    public function __construct() {
        $this->db = new Connection();
        $this->table = "orders_services_assigned";
    }

    public function getAllForClient($orderId)
    {
        $sql = "SELECT * FROM orders_services_assigned WHERE id_order = :orderId";
        $this->db->query($sql);
        $this->db->bind(":orderId", $orderId);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function getAllWithoutOwner(array $criteriaVals): array
    {
        $columnsSQL = "*";
        $keys = array_keys($criteriaVals);
        $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));

        $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where";
        $this->db->query($query);

        foreach ($criteriaVals as $key => $val) {
            $this->db->bind(":$key", $val);
        }

        return $this->db->fetchAll();
    }


    
}
