<?php

namespace App\Repositories;

class OrdersAcceptanceContractsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_acceptance_contracts";
        $this->db = new Connection();
    }

    public function getByOrder(int $orderId): ?object
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_order` = :id_order ORDER BY id DESC LIMIT 1");
        $this->db->bind(":id_order", $orderId);
        
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getAllByOrder(int $orderId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_order` = :id_order ORDER BY id DESC");
        $this->db->bind(":id_order", $orderId);
        
        return $this->db->fetchAll();
    }
}
