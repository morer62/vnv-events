<?php

namespace App\Repositories;

class StoreProductsCategoriesRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_owner',
        'id_product',
        'id_category'
    ];

    public function __construct()
    {
        $this->table = "store_products_categories";
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

    public function getCategoryIdsByProduct(int $productId): array
    {
        $this->db->query("
            SELECT id_category
            FROM {$this->table}
            WHERE id_product = :id_product
        ");
        $this->db->bind(':id_product', $productId);

        $rows = $this->db->fetchAll();
        return array_map(function ($row) {
            return (int)(is_object($row) ? $row->id_category : $row['id_category']);
        }, $rows ?: []);
    }

    public function getCategoriesByProduct(int $productId): array
    {
        $this->db->query("
            SELECT c.*
            FROM {$this->table} pc
            INNER JOIN store_categories c ON c.id = pc.id_category
            WHERE pc.id_product = :id_product
            ORDER BY c.name ASC
        ");
        $this->db->bind(':id_product', $productId);
        return $this->db->fetchAll();
    }
}