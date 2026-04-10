<?php

namespace App\Repositories;

class StoreProductsAttributesRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_owner',
        'id_product',
        'id_attribute',
        'id_attribute_value'
    ];

    public function __construct()
    {
        $this->table = "store_products_attributes";
        $this->db = new Connection();
    }

    public function deleteByProduct(int $productId): bool
    {
        try {
            $this->db->query("DELETE FROM {$this->table} WHERE id_product = :id_product");
            $this->db->bind(':id_product', $productId);
            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function getByProduct(int $productId): array
    {
        $this->db->query("
            SELECT spa.*, sa.name AS attribute_name, sav.value AS attribute_value
            FROM {$this->table} spa
            INNER JOIN store_attributes sa ON sa.id = spa.id_attribute
            INNER JOIN store_attribute_values sav ON sav.id = spa.id_attribute_value
            WHERE spa.id_product = :id_product
            ORDER BY sa.name ASC, sav.value ASC
        ");
        $this->db->bind(':id_product', $productId);
        return $this->db->fetchAll();
    }

    public function getGroupedByProduct(int $productId): array
    {
        $rows = $this->getByProduct($productId);
        $grouped = [];

        foreach ($rows as $row) {
            $attributeName = is_object($row) ? $row->attribute_name : $row['attribute_name'];
            $attributeValue = is_object($row) ? $row->attribute_value : $row['attribute_value'];

            if (!isset($grouped[$attributeName])) {
                $grouped[$attributeName] = [];
            }

            $grouped[$attributeName][] = $attributeValue;
        }

        return $grouped;
    }
}