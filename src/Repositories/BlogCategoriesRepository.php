<?php

namespace App\Repositories;

class BlogCategoriesRepository extends BaseRepository
{
    protected string $table = "blog_categories";

    protected array $fields = [
        'id',
        'id_owner',
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'featured_image_url',
        'schema_json',
        'status',
        'created_at',
        'updated_at',
    ];

    public function getActive(): array
    {
        if ($this->hasStatusColumn()) {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE `status` = 'ACTIVE'
                ORDER BY `name` ASC
            ");
        } else {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                ORDER BY `name` ASC
            ");
        }

        return $this->db->fetchAll() ?: [];
    }

    private function hasStatusColumn(): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE 'status'");
            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getBySlug(string $slug): ?object
    {
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `slug` = :slug
            LIMIT 1
        ");
        $this->db->bind(':slug', trim($slug));

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function slugExists(string $slug, int $excludeId = 0): bool
    {
        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE LOWER(`slug`) = LOWER(:slug)
        ";

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':slug', trim($slug));

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }

    public function getAllForPanel(): array
    {
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            ORDER BY `id` DESC
        ");

        return $this->db->fetchAll() ?: [];
    }
}