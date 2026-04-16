<?php

namespace App\Repositories;

class StoreProductVariationValuesRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_owner',
        'id_variation',
        'id_attribute',
        'id_attribute_value'
    ];

    public function __construct()
    {
        $this->table = "store_product_variation_values";
        $this->db = new Connection();
    }

    public function getByVariation(int $variationId): array
    {
        $this->db->query("
            SELECT 
                spvv.*,
                sa.name AS attribute_name,
                sa.slug AS attribute_slug,
                sav.value AS attribute_value,
                sav.slug AS attribute_value_slug
            FROM {$this->table} spvv
            INNER JOIN store_attributes sa ON sa.id = spvv.id_attribute
            INNER JOIN store_attribute_values sav ON sav.id = spvv.id_attribute_value
            WHERE spvv.id_variation = :id_variation
            ORDER BY sa.name ASC, sav.value ASC
        ");
        $this->db->bind(':id_variation', $variationId, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getGroupedByVariation(int $variationId): array
    {
        $rows = $this->getByVariation($variationId);
        $grouped = [];

        foreach ($rows as $row) {
            $attributeName = is_object($row) ? $row->attribute_name : $row['attribute_name'];
            $attributeSlug = is_object($row) ? $row->attribute_slug : $row['attribute_slug'];
            $attributeValue = is_object($row) ? $row->attribute_value : $row['attribute_value'];
            $attributeValueSlug = is_object($row) ? $row->attribute_value_slug : $row['attribute_value_slug'];

            $grouped[] = [
                'attribute_name' => $attributeName,
                'attribute_slug' => $attributeSlug,
                'attribute_value' => $attributeValue,
                'attribute_value_slug' => $attributeValueSlug,
            ];
        }

        return $grouped;
    }

    public function deleteByVariation(int $variationId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_variation = :id_variation
            ");
            $this->db->bind(':id_variation', $variationId, \PDO::PARAM_INT);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function deleteByProduct(int $productId): bool
    {
        try {
            $this->db->query("
                DELETE spvv
                FROM {$this->table} spvv
                INNER JOIN store_product_variations spv ON spv.id = spvv.id_variation
                WHERE spv.id_product = :id_product
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

    public function replaceByVariation(int $variationId, array $attributePairs): bool
    {
        $this->deleteByVariation($variationId);

        foreach ($attributePairs as $pair) {
            $attributeId = (int)($pair['id_attribute'] ?? 0);
            $attributeValueId = (int)($pair['id_attribute_value'] ?? 0);

            if ($attributeId <= 0 || $attributeValueId <= 0) {
                continue;
            }

            $ok = $this->add([
                'id_variation' => $variationId,
                'id_attribute' => $attributeId,
                'id_attribute_value' => $attributeValueId
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function getMapByVariationIds(array $variationIds): array
    {
        $variationIds = array_values(array_filter(array_map('intval', $variationIds)));
        if (!$variationIds) {
            return [];
        }

        $holders = [];
        foreach ($variationIds as $i => $variationId) {
            $holders[] = ':variation_' . $i;
        }

        $this->db->query("
            SELECT 
                spvv.id_variation,
                sa.name AS attribute_name,
                sa.slug AS attribute_slug,
                sav.value AS attribute_value,
                sav.slug AS attribute_value_slug
            FROM {$this->table} spvv
            INNER JOIN store_attributes sa ON sa.id = spvv.id_attribute
            INNER JOIN store_attribute_values sav ON sav.id = spvv.id_attribute_value
            WHERE spvv.id_variation IN (" . implode(',', $holders) . ")
            ORDER BY spvv.id_variation ASC, sa.name ASC, sav.value ASC
        ");

        foreach ($variationIds as $i => $variationId) {
            $this->db->bind(':variation_' . $i, $variationId, \PDO::PARAM_INT);
        }

        $rows = $this->db->fetchAll();
        $map = [];

        foreach ($rows as $row) {
            $variationId = (int)(is_object($row) ? $row->id_variation : $row['id_variation']);

            if (!isset($map[$variationId])) {
                $map[$variationId] = [];
            }

            $map[$variationId][] = [
                'attribute_name' => is_object($row) ? $row->attribute_name : $row['attribute_name'],
                'attribute_slug' => is_object($row) ? $row->attribute_slug : $row['attribute_slug'],
                'attribute_value' => is_object($row) ? $row->attribute_value : $row['attribute_value'],
                'attribute_value_slug' => is_object($row) ? $row->attribute_value_slug : $row['attribute_value_slug'],
            ];
        }

        return $map;
    }
}