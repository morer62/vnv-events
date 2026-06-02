<?php

namespace App\Repositories;

class StoreAttributeValuesRepository extends BaseRepository
{
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';

    protected array $fields = [
        'id',
        'id_owner',
        'id_attribute',
        'value',
        'slug',
        'sort_order',
        'status'
    ];

    public function __construct()
    {
        $this->table = "store_attribute_values";
        $this->db = new Connection();
    }

    public function generateUniqueSlug(int $attributeId, string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value)));
        $slug = trim($slug, '-');

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($attributeId, $slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(int $attributeId, string $slug): bool
    {
        $this->db->query("
            SELECT id 
            FROM {$this->table} 
            WHERE id_attribute = :id_attribute AND slug = :slug 
            LIMIT 1
        ");
        $this->db->bind(':id_attribute', $attributeId);
        $this->db->bind(':slug', $slug);
        return $this->db->fetchOne() !== false;
    }

    public function getByAttribute(int $attributeId): array
    {
        return $this->getByAttributeScoped($attributeId);
    }

    public function getByAttributeScoped(int $attributeId, ?int $ownerId = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $this->db->query("
            SELECT * 
            FROM {$this->table}
            WHERE id_attribute = :id_attribute
              {$ownerSql}
            ORDER BY sort_order ASC, value ASC
        ");
        $this->db->bind(':id_attribute', $attributeId);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        return $this->db->fetchAll();
    }

    public function getActiveByAttribute(int $attributeId, ?int $ownerId = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $this->db->query("
            SELECT * 
            FROM {$this->table}
            WHERE id_attribute = :id_attribute
              AND status = :status
              {$ownerSql}
            ORDER BY sort_order ASC, value ASC
        ");
        $this->db->bind(':id_attribute', $attributeId);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        return $this->db->fetchAll();
    }
}
