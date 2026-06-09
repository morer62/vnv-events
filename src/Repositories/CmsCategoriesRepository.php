<?php

namespace App\Repositories;

use App\Repositories\Concerns\ContentOriginRepositoryTrait;
use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class CmsCategoriesRepository extends BaseRepository
{
    use ContentOriginRepositoryTrait;
    use SiteScopedRepositoryTrait;

    protected string $table = "cms_categories";

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'name',
        'slug',
        'description',
        'featured_image_url',
        'is_active',
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
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `is_active` = 1 {$siteSql} ORDER BY `name` ASC");
        $this->bindSiteScope();
        return $this->db->fetchAll();
    }

    public function getAllForPanel(): array
    {
        $siteSql = $this->siteScopeSql();
        $this->db->query("SELECT * FROM `{$this->table}` WHERE 1=1 {$siteSql} ORDER BY `id` DESC");
        $this->bindSiteScope();

        return $this->db->fetchAll() ?: [];
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
