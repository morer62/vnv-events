<?php

namespace App\Repositories;

class StoreAttributesRepository extends BaseRepository
{
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';

    protected array $fields = [
        'id',
        'id_owner',
        'name',
        'slug',
        'status',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_attributes";
        $this->db = new Connection();
    }

    public function generateUniqueSlug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $slug = trim($slug, '-');

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $this->db->query("SELECT id FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $this->db->bind(':slug', $slug);
        return $this->db->fetchOne() !== false;
    }

    public function getBySlug(string $slug): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $this->db->bind(':slug', $slug);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getActive(?int $ownerId = null): array
    {
        if ($ownerId !== null && $ownerId > 0) {
            return $this->getAllBy(['status' => self::STATUS_ACTIVE, 'id_owner' => $ownerId]);
        }

        return $this->getAllBy(['status' => self::STATUS_ACTIVE]);
    }
}
