<?php

namespace App\Repositories;

class StoreProductsMealStylesRepository extends BaseRepository
{
    const STYLE_BALANCED = 'balanced';
    const STYLE_VEGETARIAN = 'vegetarian';
    const STYLE_FAMILY_FRIENDLY = 'family_friendly';
    const STYLE_PROTEIN_FOCUSED = 'protein_focused';
    const STYLE_CORPORATE_LUNCH = 'corporate_lunch';

    protected array $fields = [
        'id',
        'id_owner',
        'id_product',
        'meal_style',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_products_meal_styles";
        $this->db = new Connection();
    }

    public function getByProduct(int $productId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_product = :id_product
            ORDER BY meal_style ASC
        ");
        $this->db->bind(':id_product', $productId);

        return $this->db->fetchAll();
    }

    public function getMealStylesByProduct(int $productId): array
    {
        $rows = $this->getByProduct($productId);

        return array_map(function ($row) {
            return is_object($row) ? $row->meal_style : $row['meal_style'];
        }, $rows ?: []);
    }

    public function deleteByProduct(int $productId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE id_product = :id_product
            ");
            $this->db->bind(':id_product', $productId);

            return (bool)$this->db->execute();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function replaceByProduct(int $productId, array $mealStyles): bool
    {
        $this->deleteByProduct($productId);

        $mealStyles = array_values(array_unique(array_filter(array_map('trim', $mealStyles))));

        foreach ($mealStyles as $mealStyle) {
            $ok = $this->add([
                'id_product' => $productId,
                'meal_style' => $mealStyle
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function getProductsByMealStyle(string $mealStyle): array
    {
        $this->db->query("
            SELECT spms.*, sp.*
            FROM {$this->table} spms
            INNER JOIN store_products sp ON sp.id = spms.id_product
            WHERE spms.meal_style = :meal_style
              AND sp.status = 'ACTIVE'
              AND sp.is_public = 1
            ORDER BY sp.is_featured DESC, sp.id DESC
        ");
        $this->db->bind(':meal_style', $mealStyle);

        return $this->db->fetchAll();
    }
}