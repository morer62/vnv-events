<?php

namespace App\Repositories;

class StoreSubscriptionItemsRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_owner',
        'id_subscription',
        'id_product',
        'product_name_snapshot',
        'quantity',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_subscription_items";
        $this->db = new Connection();
    }

    public function getBySubscription(int $subscriptionId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_subscription = :id_subscription
            ORDER BY id ASC
        ");
        $this->db->bind(':id_subscription', $subscriptionId);

        return $this->db->fetchAll();
    }

    public function getDetailedBySubscription(int $subscriptionId): array
    {
        $this->db->query("
            SELECT ssi.*, sp.main_image, sp.slug
            FROM {$this->table} ssi
            LEFT JOIN store_products sp ON sp.id = ssi.id_product
            WHERE ssi.id_subscription = :id_subscription
            ORDER BY ssi.id ASC
        ");
        $this->db->bind(':id_subscription', $subscriptionId);

        return $this->db->fetchAll();
    }

    public function deleteBySubscription(int $subscriptionId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_subscription = :id_subscription
            ");
            $this->db->bind(':id_subscription', $subscriptionId);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function replaceItems(int $subscriptionId, array $items): bool
    {
        $this->deleteBySubscription($subscriptionId);

        foreach ($items as $item) {
            $ok = $this->add([
                'id_subscription' => $subscriptionId,
                'id_product' => is_object($item) ? $item->id_product : $item['id_product'],
                'product_name_snapshot' => is_object($item) ? $item->product_name_snapshot : $item['product_name_snapshot'],
                'quantity' => is_object($item) ? $item->quantity : $item['quantity']
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function createFromOrderItems(int $subscriptionId, array $orderItems): bool
    {
        foreach ($orderItems as $item) {
            $ok = $this->add([
                'id_subscription' => $subscriptionId,
                'id_product' => is_object($item) ? $item->id_product : $item['id_product'],
                'product_name_snapshot' => is_object($item) ? $item->product_name_snapshot : $item['product_name_snapshot'],
                'quantity' => is_object($item) ? $item->quantity : $item['quantity']
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function getMealsCount(int $subscriptionId): int
    {
        $this->db->query("
            SELECT COALESCE(SUM(quantity), 0) AS meals_count
            FROM {$this->table}
            WHERE id_subscription = :id_subscription
        ");
        $this->db->bind(':id_subscription', $subscriptionId);

        $result = $this->db->fetchOne();
        return (int)($result->meals_count ?? 0);
    }
}