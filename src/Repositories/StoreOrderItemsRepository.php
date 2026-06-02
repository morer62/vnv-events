<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StoreOrderItemsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const PRICING_PAYG = 'PAYG';
    const PRICING_SUBSCRIPTION = 'SUBSCRIPTION';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'id_store_order',
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
        $this->table = "store_order_items";
        $this->db = new Connection();
        $this->ensureVariationColumns();
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
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

    public function getByOrder(int $orderId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
            ORDER BY id ASC
        ");
        $this->db->bind(':id_store_order', $orderId, \PDO::PARAM_INT);

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
        $this->db->bind(':id_store_order', $orderId, \PDO::PARAM_INT);

        $rows = $this->db->fetchAll();

        foreach ($rows as $row) {
            if (is_object($row)) {
                $row->variation_options = $this->decodeVariationOptions($row->variation_options_snapshot ?? null);
                $row->display_label = $this->getDisplayLabel($row);
            }
        }

        return $rows;
    }

    public function deleteByOrder(int $orderId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_store_order = :id_store_order
            ");
            $this->db->bind(':id_store_order', $orderId, \PDO::PARAM_INT);

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
            $itemData = is_object($item) ? $item : (object)$item;

            $ok = $this->add([
                'id_store_order' => $orderId,
                'id_product' => (int)($itemData->id_product ?? 0),
                'id_product_variation' => !empty($itemData->id_product_variation)
                    ? (int)$itemData->id_product_variation
                    : null,
                'product_name_snapshot' => (string)($itemData->product_name_snapshot ?? ''),
                'variation_name_snapshot' => !empty($itemData->variation_name_snapshot)
                    ? (string)$itemData->variation_name_snapshot
                    : null,
                'variation_options_snapshot' => !empty($itemData->variation_options_snapshot)
                    ? (string)$itemData->variation_options_snapshot
                    : null,
                'unit_price' => (float)($itemData->unit_price ?? 0),
                'pricing_mode' => (string)($itemData->pricing_mode ?? self::PRICING_PAYG),
                'quantity' => (int)($itemData->quantity ?? 1),
                'line_total' => (float)($itemData->line_total ?? 0),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
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

    public function getOneByOrderProductAndVariation(int $orderId, int $productId, ?int $variationId = null): ?object
    {
        $variationId = (int)$variationId;

        if ($variationId > 0) {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE id_store_order = :id_store_order
                  AND id_product = :id_product
                  AND id_product_variation = :id_product_variation
                LIMIT 1
            ");
            $this->db->bind(':id_store_order', $orderId, \PDO::PARAM_INT);
            $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
            $this->db->bind(':id_product_variation', $variationId, \PDO::PARAM_INT);
        } else {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE id_store_order = :id_store_order
                  AND id_product = :id_product
                  AND (id_product_variation IS NULL OR id_product_variation = 0)
                LIMIT 1
            ");
            $this->db->bind(':id_store_order', $orderId, \PDO::PARAM_INT);
            $this->db->bind(':id_product', $productId, \PDO::PARAM_INT);
        }

        $result = $this->db->fetchOne();
        return $result ?: null;
    }
}
