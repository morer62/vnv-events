<?php

namespace App\Repositories;

class OrdersServiceStepRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_services_steps";
        $this->db = new Connection();
    }

    public function getByServiceId(int $idService): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_service = :id");
        $this->db->bind(":id", $idService);
        return $this->db->fetchAll();
    }
}
