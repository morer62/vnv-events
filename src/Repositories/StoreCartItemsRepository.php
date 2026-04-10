<?php

namespace App\Repositories;

class StoreCartItemsRepository extends BaseRepository
{
    const PRICING_PAYG = 'PAYG';
    const PRICING_SUBSCRIPTION = 'SUBSCRIPTION';

    protected array $fields = [
        'id',
        'id_owner',
        'id_cart',
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
        $this->table = "store_cart_items";
        $this->db = new Connection();
    }

    public function getByCart(int $cartId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_cart = :id_cart
            ORDER BY id ASC
        ");
        $this->db->bind(':id_cart', $cartId);

        return $this->db->fetchAll();
    }

    public function getDetailedByCart(int $cartId): array
    {
        $this->db->query("
            SELECT sci.*, sp.main_image, sp.slug
            FROM {$this->table} sci
            LEFT JOIN store_products sp ON sp.id = sci.id_product
            WHERE sci.id_cart = :id_cart
            ORDER BY sci.id ASC
        ");
        $this->db->bind(':id_cart', $cartId);

        return $this->db->fetchAll();
    }

    public function getOneByCartAndProduct(int $cartId, int $productId): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_cart = :id_cart
              AND id_product = :id_product
            LIMIT 1
        ");
        $this->db->bind(':id_cart', $cartId);
        $this->db->bind(':id_product', $productId);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function deleteByCart(int $cartId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_cart = :id_cart
            ");
            $this->db->bind(':id_cart', $cartId);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function deleteByCartAndProduct(int $cartId, int $productId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_cart = :id_cart
                  AND id_product = :id_product
            ");
            $this->db->bind(':id_cart', $cartId);
            $this->db->bind(':id_product', $productId);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function getCartTotals(int $cartId): array
    {
        $this->db->query("
            SELECT 
                COUNT(*) AS items_count,
                COALESCE(SUM(quantity), 0) AS meals_count,
                COALESCE(SUM(line_total), 0) AS subtotal
            FROM {$this->table}
            WHERE id_cart = :id_cart
        ");
        $this->db->bind(':id_cart', $cartId);

        $result = $this->db->fetchOne();

        return [
            'items_count' => (int)($result->items_count ?? 0),
            'meals_count' => (int)($result->meals_count ?? 0),
            'subtotal' => (float)($result->subtotal ?? 0)
        ];
    }

    public function addOrUpdateProduct(
        int $cartId,
        int $productId,
        string $productName,
        float $unitPrice,
        int $quantity,
        string $pricingMode = self::PRICING_PAYG
    ): bool {
        $existing = $this->getOneByCartAndProduct($cartId, $productId);
        $lineTotal = round($unitPrice * $quantity, 2);

        if ($existing) {
            return $this->update([
                'product_name_snapshot' => $productName,
                'unit_price' => $unitPrice,
                'pricing_mode' => $pricingMode,
                'quantity' => $quantity,
                'line_total' => $lineTotal
            ], [
                'id' => $existing->id
            ]);
        }

        return $this->add([
            'id_cart' => $cartId,
            'id_product' => $productId,
            'product_name_snapshot' => $productName,
            'unit_price' => $unitPrice,
            'pricing_mode' => $pricingMode,
            'quantity' => $quantity,
            'line_total' => $lineTotal
        ]);
    }
}