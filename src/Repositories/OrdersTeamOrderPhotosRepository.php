<?php

namespace App\Repositories;

class OrdersTeamOrderPhotosRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = "orders_team_order_photos";
    }

    public function getByOrderAndUser(int $orderId, int $userId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_order` = :orderId AND `id_user` = :userId ORDER BY `uploaded_at` DESC");
        $this->db->bind(":orderId", $orderId);
        $this->db->bind(":userId", $userId);
        return $this->db->fetchAll();
    }

    public function getByOrder(int $orderId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_order` = :orderId ORDER BY `uploaded_at` DESC");
        $this->db->bind(":orderId", $orderId);
        return $this->db->fetchAll();
    }
}
