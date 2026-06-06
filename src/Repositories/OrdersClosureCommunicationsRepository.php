<?php

namespace App\Repositories;

class OrdersClosureCommunicationsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = "orders_closure_communications";
    }

    public function getAllByOrder(int $orderId): array
    {
        return $this->getAllBy(["id_order" => $orderId]);
    }
}
