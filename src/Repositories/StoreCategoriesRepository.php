<?php

namespace App\Repositories;

class StoreCategoriesRepository extends BaseRepository
{
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';

    protected array $fields = [
        'id',
        'id_owner',
        'name',
        'slug',
        'description',
        'icon',
        'meta_title',
        'meta_description',
        'page_builder_json',
        'status',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_categories";
        $this->db = new Connection();
        $this->ensureContentColumns();
    }

    public function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value)));
        $slug = trim($slug, '-');
        return $slug;
    }

    public function generateUniqueSlug(string $baseValue, int $excludeId = 0): string
    {
        $slug = $this->normalizeSlug($baseValue);
        if ($slug === '') {
            $slug = 'category';
        }

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug, int $excludeId = 0): bool
    {
        $query = "SELECT id FROM {$this->table} WHERE slug = :slug";
        if ($excludeId > 0) {
            $query .= " AND id != :exclude_id";
        }
        $query .= " LIMIT 1";
        $this->db->query($query);
        $this->db->bind(':slug', $slug);
        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }
        return $this->db->fetchOne() !== false;
    }

    public function getBySlug(string $slug): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $this->db->bind(':slug', $slug);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getActive(): array
    {
        return $this->getAllBy(['status' => self::STATUS_ACTIVE]);
    }

    public function getPublicSitemapEntries(int $limit = 1000): array
    {
        $this->db->query("
            SELECT id, name, slug, description, meta_title, meta_description, updated_at, created_at
            FROM {$this->table}
            WHERE status = :status
              AND slug IS NOT NULL
              AND slug != ''
            ORDER BY updated_at DESC, created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll() ?: [];
    }

    private function ensureContentColumns(): void
    {
        $columnsToEnsure = [
            'slug' => "ALTER TABLE {$this->table} ADD COLUMN slug VARCHAR(255) NULL AFTER name",
            'meta_title' => "ALTER TABLE {$this->table} ADD COLUMN meta_title VARCHAR(255) NULL AFTER icon",
            'meta_description' => "ALTER TABLE {$this->table} ADD COLUMN meta_description TEXT NULL AFTER meta_title",
            'page_builder_json' => "ALTER TABLE {$this->table} ADD COLUMN page_builder_json LONGTEXT NULL AFTER meta_description",
        ];

        foreach ($columnsToEnsure as $column => $sql) {
            if (!$this->hasColumn($column)) {
                $this->db->query($sql);
                $this->db->execute();
            }
        }
    }

    private function hasColumn(string $column): bool
    {
        $this->db->query("SHOW COLUMNS FROM {$this->table} LIKE :column_name");
        $this->db->bind(':column_name', $column);
        return $this->db->fetchOne() !== false;
    }
}
