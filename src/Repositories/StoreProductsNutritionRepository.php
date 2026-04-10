<?php

namespace App\Repositories;

class StoreProductsNutritionRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_owner',
        'id_product',
        'calories',
        'protein',
        'carbohydrates',
        'fat',
        'fiber',
        'sugar',
        'sodium',
        'serving_size',
        'ingredients',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_products_nutrition";
        $this->db = new Connection();
    }

    public function getByProduct(int $productId): ?object
    {
        return $this->getOne(['id_product' => $productId]);
    }

    public function saveForProduct(int $productId, array $data): bool
    {
        $existing = $this->getByProduct($productId);

        $data['id_product'] = $productId;
        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($existing) {
            unset($data['id_product']);
            return $this->update($data, ['id_product' => $productId]);
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        return $this->add($data);
    }
}