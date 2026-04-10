<?php

namespace App\Repositories;

class StoreProductsAudiencesRepository extends BaseRepository
{
    const AUDIENCE_PROFESSIONAL = 'professional';
    const AUDIENCE_HEALTHY_ACTIVE = 'healthy_active';
    const AUDIENCE_FAMILY_KIDS = 'family_kids';
    const AUDIENCE_CORPORATE = 'corporate';

    protected array $fields = [
        'id',
        'id_owner',
        'id_product',
        'audience_type',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_products_audiences";
        $this->db = new Connection();
    }

    public function getByProduct(int $productId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_product = :id_product
            ORDER BY audience_type ASC
        ");
        $this->db->bind(':id_product', $productId);

        return $this->db->fetchAll();
    }

    public function getAudienceTypesByProduct(int $productId): array
    {
        $rows = $this->getByProduct($productId);

        return array_map(function ($row) {
            return is_object($row) ? $row->audience_type : $row['audience_type'];
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

    public function replaceByProduct(int $productId, array $audiences): bool
    {
        $this->deleteByProduct($productId);

        $audiences = array_values(array_unique(array_filter(array_map('trim', $audiences))));

        foreach ($audiences as $audienceType) {
            $ok = $this->add([
                'id_product' => $productId,
                'audience_type' => $audienceType
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function getProductsByAudience(string $audienceType): array
    {
        $this->db->query("
            SELECT spa.*, sp.*
            FROM {$this->table} spa
            INNER JOIN store_products sp ON sp.id = spa.id_product
            WHERE spa.audience_type = :audience_type
              AND sp.status = 'ACTIVE'
              AND sp.is_public = 1
            ORDER BY sp.is_featured DESC, sp.id DESC
        ");
        $this->db->bind(':audience_type', $audienceType);

        return $this->db->fetchAll();
    }
}