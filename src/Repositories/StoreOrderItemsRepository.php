<?php

namespace App\Repositories;

class StoreOrderItemsRepository extends BaseRepository
{
    const PRICING_PAYG = 'PAYG';
    const PRICING_SUBSCRIPTION = 'SUBSCRIPTION';

    protected array $fields = [
        'id',
        'id_owner',
        'id_store_order',
        'id_product',
        'product_name_snapshot',
        'unit_price',
        'pricing_mode',
        'quantity',
        'line_total',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_order_items";
        $this->db = new Connection();
    }

    public function getByOrder(int $orderId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
            ORDER BY id ASC
        ");
        $this->db->bind(':id_store_order', $orderId);

        return $this->db->fetchAll();
    }

    public function getDetailedByOrder(int $orderId): array
    {
        $this->db->query("
            SELECT soi.*, sp.main_image, sp.slug
            FROM {$this->table} soi
            LEFT JOIN store_products sp ON sp.id = soi.id_product
            WHERE soi.id_store_order = :id_store_order
            ORDER BY soi.id ASC
        ");
        $this->db->bind(':id_store_order', $orderId);

        return $this->db->fetchAll();
    }

    public function deleteByOrder(int $orderId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_store_order = :id_store_order
            ");
            $this->db->bind(':id_store_order', $orderId);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function createFromCartItems(int $orderId, array $cartItems): bool
    {
        foreach ($cartItems as $item) {
            $ok = $this->add([
                'id_store_order' => $orderId,
                'id_product' => is_object($item) ? $item->id_product : $item['id_product'],
                'product_name_snapshot' => is_object($item) ? $item->product_name_snapshot : $item['product_name_snapshot'],
                'unit_price' => is_object($item) ? $item->unit_price : $item['unit_price'],
                'pricing_mode' => is_object($item) ? $item->pricing_mode : $item['pricing_mode'],
                'quantity' => is_object($item) ? $item->quantity : $item['quantity'],
                'line_total' => is_object($item) ? $item->line_total : $item['line_total']
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function getOrderTotals(int $orderId): array
    {
        $this->db->query("
            SELECT
                COUNT(*) AS items_count,
                COALESCE(SUM(quantity), 0) AS meals_count,
                COALESCE(SUM(line_total), 0) AS subtotal
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
        ");
        $this->db->bind(':id_store_order', $orderId);

        $result = $this->db->fetchOne();

        return [
            'items_count' => (int)($result->items_count ?? 0),
            'meals_count' => (int)($result->meals_count ?? 0),
            'subtotal' => (float)($result->subtotal ?? 0)
        ];
    }

    public function getPreparationTotalsByOrders(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        $orderIds = array_map('intval', $orderIds);
        $placeholders = [];
        foreach ($orderIds as $idx => $_id) {
            $placeholders[] = ':id_' . $idx;
        }

        $this->db->query("
            SELECT
                product_name_snapshot AS product_name,
                SUM(quantity) AS total_qty
            FROM {$this->table}
            WHERE id_store_order IN (" . implode(',', $placeholders) . ")
            GROUP BY product_name_snapshot
            ORDER BY total_qty DESC, product_name_snapshot ASC
        ");
        foreach ($orderIds as $idx => $id) {
            $this->db->bind(':id_' . $idx, $id, \PDO::PARAM_INT);
        }

        return $this->db->fetchAll();
    }
}