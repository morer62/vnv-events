<?php

namespace App\Repositories;

class OrdersPaymentsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_payments";
        $this->db = new Connection();
    }

    public function getAllByOrder(int $orderId): array
    {
        return $this->getAllBy(["id_order" => $orderId]);
    }

    /**
     * Pagos de la ORDEN PRINCIPAL (excluye pagos de subórdenes)
     */
    public function getMainByOrder(int $orderId): array
    {
        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE id_order = :orderId
              AND (id_suborder IS NULL OR id_suborder = 0)
        ";
        $this->db->query($sql);
        $this->db->bind(':orderId', $orderId);
        return $this->db->fetchAll();
    }

    public function getAllByOwner(int $ownerId): array
    {
        $sql = "
            SELECT p.*
            FROM orders_payments p
            JOIN orders o ON p.id_order = o.id
            WHERE o.id_owner = :ownerId
            ORDER BY p.paid_at DESC
        ";
        $this->db->query($sql);
        $this->db->bind(":ownerId", $ownerId);
        return $this->db->fetchAll();
    }

    public function markRefunded(string $chargeId, float $amount): void {
        $this->update([
            'refunded_at' => date('Y-m-d H:i:s'),
            'refunded_amount' => $amount
        ], [
            'stripe_charge_id' => $chargeId
        ]);
    }

}
