<?php

namespace App\Repositories;

class OrdersServicesShoppingListRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = 'orders_services_shopping_list';
        $this->db = new Connection();
    }

    /**
     * Obtiene todos los ítems para una orden + servicio específico
     */
    public function getByOrderAndService(int $orderId, int $serviceId): array
    {
        return $this->getAllBy([
            "id_order" => $orderId,
            "id_service" => $serviceId
        ]);
    }

    /**
     * Elimina todos los ítems de una orden + servicio específico
     */
    public function deleteByOrderAndService(int $orderId, int $serviceId): bool
    {
        try {
            $this->db->query("DELETE FROM {$this->table} WHERE id_order = :order_id AND id_service = :service_id");
            $this->db->bind(":order_id", $orderId);
            $this->db->bind(":service_id", $serviceId);
            $this->db->execute();
            return true;
        } catch (\PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return false;
        }
    }

    /**
     * Obtiene todos los ítems para una orden completa (usado en PDF si se desea)
     */
    public function getAllByOrder(int $orderId): array
    {
        return $this->getAllBy(["id_order" => $orderId]);
    }

    public function getBySuborderAndService(int $suborderId, int $serviceId): array
    {
        return $this->getAllBy([
            "id_suborder" => $suborderId,
            "id_service" => $serviceId
        ]);
    }

    public function deleteBySuborderAndService(int $suborderId, int $serviceId): bool
    {
        try {
            $this->db->query("DELETE FROM {$this->table} WHERE id_suborder = :suborder_id AND id_service = :service_id");
            $this->db->bind(":suborder_id", $suborderId);
            $this->db->bind(":service_id", $serviceId);
            $this->db->execute();
            return true;
        } catch (\PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return false;
        }
    }
}
