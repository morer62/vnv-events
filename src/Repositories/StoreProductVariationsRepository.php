<?php

namespace App\Repositories;

class StoreProductVariationsRepository extends BaseRepository
{
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';

    protected array $fields = [
        'id',
        'id_owner',
        'id_product',
        'name',
        'slug',
        'sku',
        'price',
        'promo_price',
        'stock_quantity',
        'min_purchase_qty',
        'max_purchase_qty',
        'sort_order',
        'status',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_product_variations";
        $this->db = new Connection();
    }

    public function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value)));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'variation';
    }

    public function generateUniqueSlug(int $productId, string $baseValue, int $excludeId = 0): string
    {
        $slug = $this->normalizeSlug($baseValue);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($productId, $slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(int $productId, string $slug, int $excludeId = 0): bool
    {
        $sql = "
            SELECT id
            FROM {$this->table}
            WHERE id_product = :id_product
              AND slug = :slug
        ";

        if ($excludeId > 0) {
            $sql .= " AND id != :exclude_id";
        }

        $sql .= " LIMIT 1";

        $this->db->query($sql);
        $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
        $this->db->bind(':slug', $slug);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId, \PDO::PARAM_INT);
        }

        return $this->db->fetchOne() !== false;
    }

    public function getById(int $id): ?object
    {
        return $this->getOne(['id' => $id]) ?: null;
    }

    public function getByProduct(int $productId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_product = :id_product
            ORDER BY sort_order ASC, id ASC
        ");
        $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getActiveByProduct(int $productId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_product = :id_product
              AND status = :status
            ORDER BY sort_order ASC, id ASC
        ");
        $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
        $this->db->bind(':status', self::STATUS_ACTIVE);

        return $this->db->fetchAll();
    }

    public function getByProductAndId(int $productId, int $variationId): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_product = :id_product
              AND id = :id
            LIMIT 1
        ");
        $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
        $this->db->bind(':id', $variationId, \PDO::PARAM_INT);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function deleteByProduct(int $productId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_product = :id_product
            ");
            $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function getPriceRangeByProduct(int $productId): array
    {
        $this->db->query("
            SELECT 
                MIN(CASE 
                    WHEN promo_price IS NOT NULL AND promo_price > 0 THEN promo_price
                    ELSE price
                END) AS min_price,
                MAX(CASE 
                    WHEN promo_price IS NOT NULL AND promo_price > 0 THEN promo_price
                    ELSE price
                END) AS max_price
            FROM {$this->table}
            WHERE id_product = :id_product
              AND status = :status
        ");
        $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
        $this->db->bind(':status', self::STATUS_ACTIVE);

        $row = $this->db->fetchOne();

        return [
            'min_price' => isset($row->min_price) ? (float)$row->min_price : 0.0,
            'max_price' => isset($row->max_price) ? (float)$row->max_price : 0.0,
        ];
    }

    public function getEffectivePrice(object|array $variation): float
    {
        $promoPrice = (float)(is_object($variation) ? ($variation->promo_price ?? 0) : ($variation['promo_price'] ?? 0));
        $price = (float)(is_object($variation) ? ($variation->price ?? 0) : ($variation['price'] ?? 0));

        if ($promoPrice > 0) {
            return $promoPrice;
        }

        return $price;
    }

    public function replaceByProduct(int $productId, array $variations): bool
    {
        $variationValuesRepo = new StoreProductVariationValuesRepository();

        $existing = $this->getByProduct($productId);
        foreach ($existing as $oldVariation) {
            $oldVariationId = (int)(is_object($oldVariation) ? $oldVariation->id : $oldVariation['id']);
            $variationValuesRepo->deleteByVariation($oldVariationId);
        }

        $this->deleteByProduct($productId);

        $sortOrder = 0;

        foreach ($variations as $variation) {
            $name = trim((string)($variation['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $slug = $this->generateUniqueSlug($productId, $variation['slug'] ?? $name);

            $ok = $this->add([
            'id_product' => $productId,
            'name' => $name,
            'slug' => $slug,
            'sku' => trim((string)($variation['sku'] ?? '')),
            'price' => (float)($variation['price'] ?? 0),
            'promo_price' => ($variation['promo_price'] !== '' && $variation['promo_price'] !== null)
                ? (float)$variation['promo_price']
                : null,
            'stock_quantity' => (int)($variation['stock_quantity'] ?? 0),
            'min_purchase_qty' => max(1, (int)($variation['min_purchase_qty'] ?? 1)),
            'max_purchase_qty' => ($variation['max_purchase_qty'] !== '' && $variation['max_purchase_qty'] !== null)
                ? (int)$variation['max_purchase_qty']
                : null,
            'sort_order' => (int)($variation['sort_order'] ?? $sortOrder),
            'status' => in_array(($variation['status'] ?? self::STATUS_ACTIVE), [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)
                ? $variation['status']
                : self::STATUS_ACTIVE,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$ok) {
            return false;
        }

        $variationId = (int)$this->getLastId();
        if ($variationId <= 0) {
            return false;
        }

            if (!$ok) {
                return false;
            }

            $variationId = (int)$this->getLastId();

            $attributePairs = $variation['attribute_pairs'] ?? [];
            if ($attributePairs && !$variationValuesRepo->replaceByVariation($variationId, $attributePairs)) {
                return false;
            }

            $sortOrder++;
        }

        return true;
    }

    public function getDetailedByProduct(int $productId): array
    {
        $rows = $this->getByProduct($productId);
        if (!$rows) {
            return [];
        }

        $variationValuesRepo = new StoreProductVariationValuesRepository();

        foreach ($rows as $row) {
            $variationId = (int)(is_object($row) ? $row->id : $row['id']);
            $values = $variationValuesRepo->getGroupedByVariation($variationId);

            if (is_object($row)) {
                $row->attribute_values = $values;
                $row->effective_price = $this->getEffectivePrice($row);
            } else {
                $row['attribute_values'] = $values;
                $row['effective_price'] = $this->getEffectivePrice($row);
            }
        }

        return $rows;
    }
}