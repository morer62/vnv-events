<?php

namespace App\Repositories;

use App\Repositories\Concerns\ContentOriginRepositoryTrait;
use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class BlogCategoriesRepository extends BaseRepository
{
    use ContentOriginRepositoryTrait;
    use SiteScopedRepositoryTrait;

    protected string $table = "blog_categories";

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'featured_image_url',
        'schema_json',
        'status',
        'content_origin',
        'origin_site_key',
        'created_by',
        'updated_by',
        'origin_metadata_json',
        'created_at',
        'updated_at',
    ];

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getActive(): array
    {
        $siteSql = $this->siteScopeSql();
        if ($this->hasStatusColumn()) {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE `status` = 'ACTIVE'
                  {$siteSql}
                ORDER BY `name` ASC
            ");
        } else {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE 1=1
                  {$siteSql}
                ORDER BY `name` ASC
            ");
        }
        $this->bindSiteScope();

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
        $siteSql = $this->siteScopeSql();
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE 1=1
              {$siteSql}
            ORDER BY `id` DESC
        ");
        $this->bindSiteScope();

        return $this->db->fetchAll() ?: [];
    }
}
