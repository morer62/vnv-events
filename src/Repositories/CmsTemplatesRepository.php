<?php

namespace App\Repositories;

class CmsTemplatesRepository extends BaseRepository
{
    protected string $table = "cms_templates";

    protected array $fields = [
        'id',
        'name',
        'slug',
        'description',
        'template_source',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public function getActive(): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `is_active` = 1 ORDER BY `name` ASC");
        return $this->db->fetchAll();
    }

    public function getBySlug(string $slug): ?object
    {
        return $this->getOne([
            'slug' => $slug
        ]);
    }

    public function slugExists(string $slug, int $excludeId = 0): bool
    {
        $query = "SELECT COUNT(*) as total FROM `{$this->table}` WHERE `slug` = :slug";

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':slug', $slug);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }
}