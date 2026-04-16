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
        'id_product_variation',
        'product_name_snapshot',
        'variation_name_snapshot',
        'variation_options_snapshot',
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
        $this->ensureVariationColumns();
    }

    private function ensureVariationColumns(): void
    {
        $columns = [
            'id_product_variation' => 'INT(11) NULL AFTER `id_product`',
            'variation_name_snapshot' => 'VARCHAR(180) NULL AFTER `product_name_snapshot`',
            'variation_options_snapshot' => 'LONGTEXT NULL AFTER `variation_name_snapshot`',
        ];

        foreach ($columns as $column => $definition) {
            if ($this->columnExists($column)) {
                continue;
            }

            try {
                $this->db->query("ALTER TABLE `{$this->table}` ADD COLUMN `{$column}` {$definition}");
                $this->db->execute();
            } catch (\Throwable $e) {
                // Avoid breaking runtime if migration already ran or DDL is restricted.
            }
        }
    }

    private function columnExists(string $column): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE :column");
            $this->db->bind(':column', $column);
            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getByCart(int $cartId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_cart = :id_cart
            ORDER BY id ASC
        ");
        $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);

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
        $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);

        $rows = $this->db->fetchAll();

        foreach ($rows as $row) {
            if (is_object($row)) {
                $row->variation_options = $this->decodeVariationOptions($row->variation_options_snapshot ?? null);
            }
        }

        return $rows;
    }

    public function getOneByCartAndProduct(int $cartId, int $productId): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_cart = :id_cart
              AND id_product = :id_product
              AND (id_product_variation IS NULL OR id_product_variation = 0)
            LIMIT 1
        ");
        $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);
        $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getOneByCartProductAndVariation(int $cartId, int $productId, ?int $variationId = null): ?object
    {
        $variationId = (int)$variationId;

        if ($variationId > 0) {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE id_cart = :id_cart
                  AND id_product = :id_product
                  AND id_product_variation = :id_product_variation
                LIMIT 1
            ");
            $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);
            $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
            $this->db->bind(':id_product_variation', $variationId, \PDO::PARAM_INT);
        } else {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE id_cart = :id_cart
                  AND id_product = :id_product
                  AND (id_product_variation IS NULL OR id_product_variation = 0)
                LIMIT 1
            ");
            $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);
            $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
        }

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
            $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);

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
            $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);
            $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function deleteByCartProductAndVariation(int $cartId, int $productId, ?int $variationId = null): bool
    {
        try {
            $variationId = (int)$variationId;

            if ($variationId > 0) {
                $this->db->query("
                    DELETE FROM {$this->table}
                    WHERE id_cart = :id_cart
                      AND id_product = :id_product
                      AND id_product_variation = :id_product_variation
                ");
                $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);
                $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
                $this->db->bind(':id_product_variation', $variationId, \PDO::PARAM_INT);
            } else {
                $this->db->query("
                    DELETE FROM {$this->table}
                    WHERE id_cart = :id_cart
                      AND id_product = :id_product
                      AND (id_product_variation IS NULL OR id_product_variation = 0)
                ");
                $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);
                $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
            }

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
        $this->db->bind(':id_cart', $cartId, \PDO::PARAM_INT);

        $result = $this->db->fetchOne();

        return [
            'items_count' => (int)($result->items_count ?? 0),
            'meals_count' => (int)($result->meals_count ?? 0),
            'subtotal' => (float)($result->subtotal ?? 0),
        ];
    }

    public function addOrUpdateProduct(
        int $cartId,
        int $productId,
        string $productName,
        float $unitPrice,
        int $quantity,
        string $pricingMode = self::PRICING_PAYG,
        ?int $variationId = null,
        ?string $variationName = null,
        array|string|null $variationOptions = null
    ): bool {
        $variationId = (int)$variationId;
        $quantity = max(1, $quantity);
        $lineTotal = round($unitPrice * $quantity, 2);
        $variationOptionsJson = $this->encodeVariationOptions($variationOptions);

        $existing = $this->getOneByCartProductAndVariation(
            $cartId,
            $productId,
            $variationId > 0 ? $variationId : null
        );

        if ($existing) {
            return $this->update([
                'product_name_snapshot' => $productName,
                'id_product_variation' => $variationId > 0 ? $variationId : null,
                'variation_name_snapshot' => $variationId > 0 ? trim((string)$variationName) : null,
                'variation_options_snapshot' => $variationId > 0 ? $variationOptionsJson : null,
                'unit_price' => $unitPrice,
                'pricing_mode' => $pricingMode,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ], [
                'id' => $existing->id,
            ]);
        }

        return $this->add([
            'id_cart' => $cartId,
            'id_product' => $productId,
            'id_product_variation' => $variationId > 0 ? $variationId : null,
            'product_name_snapshot' => $productName,
            'variation_name_snapshot' => $variationId > 0 ? trim((string)$variationName) : null,
            'variation_options_snapshot' => $variationId > 0 ? $variationOptionsJson : null,
            'unit_price' => $unitPrice,
            'pricing_mode' => $pricingMode,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
        ]);
    }

    public function incrementProductQuantity(
        int $cartId,
        int $productId,
        int $quantityToAdd = 1,
        ?int $variationId = null
    ): bool {
        $existing = $this->getOneByCartProductAndVariation($cartId, $productId, $variationId);
        if (!$existing) {
            return false;
        }

        $newQty = max(1, (int)$existing->quantity + (int)$quantityToAdd);
        $lineTotal = round((float)$existing->unit_price * $newQty, 2);

        return $this->update([
            'quantity' => $newQty,
            'line_total' => $lineTotal,
        ], [
            'id' => $existing->id,
        ]);
    }

    public function updateItemQuantity(int $itemId, int $quantity): bool
    {
        $quantity = max(1, $quantity);
        $item = $this->getOne(['id' => $itemId]);

        if (!$item) {
            return false;
        }

        $lineTotal = round((float)$item->unit_price * $quantity, 2);

        return $this->update([
            'quantity' => $quantity,
            'line_total' => $lineTotal,
        ], [
            'id' => $itemId,
        ]);
    }

    public function getDisplayLabel(object|array $item): string
    {
        $productName = trim((string)(is_object($item)
            ? ($item->product_name_snapshot ?? '')
            : ($item['product_name_snapshot'] ?? '')));

        $variationName = trim((string)(is_object($item)
            ? ($item->variation_name_snapshot ?? '')
            : ($item['variation_name_snapshot'] ?? '')));

        if ($variationName !== '') {
            return $productName . ' - ' . $variationName;
        }

        return $productName;
    }

    public function decodeVariationOptions(?string $json): array
    {
        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function encodeVariationOptions(array|string|null $variationOptions): ?string
    {
        if ($variationOptions === null || $variationOptions === '') {
            return null;
        }

        if (is_string($variationOptions)) {
            return trim($variationOptions) !== '' ? $variationOptions : null;
        }

        return json_encode($variationOptions, JSON_UNESCAPED_UNICODE);
    }
}