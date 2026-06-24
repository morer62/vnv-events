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
        'featured_image_alt',
        'applies_to_pages',
        'applies_to_blog',
        'applies_to_locations',
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

    public function getActiveForContentType(string $contentType): array
    {
        $column = $this->appliesColumnForContentType($contentType);
        $siteSql = $this->siteScopeSql();

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `is_active` = 1
              AND `{$column}` = 1
              {$siteSql}
            ORDER BY `name` ASC
        ");
        $this->bindSiteScope();

        return $this->db->fetchAll() ?: [];
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

    public function getActiveBySlugForContentType(string $slug, string $contentType): ?object
    {
        $column = $this->appliesColumnForContentType($contentType);
        $siteSql = $this->siteScopeSql();

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `slug` = :slug
              AND `is_active` = 1
              AND `{$column}` = 1
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':slug', $slug);
        $this->bindSiteScope();

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function supportsContentType(object $category, string $contentType): bool
    {
        $column = $this->appliesColumnForContentType($contentType);
        return (int)($category->{$column} ?? 0) === 1;
    }

    private function appliesColumnForContentType(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));

        if (in_array($contentType, ['blog', 'post', 'blog_post', 'blog-post'], true)) {
            return 'applies_to_blog';
        }

        if (in_array($contentType, ['location', 'locations', 'location_page', 'location-page'], true)) {
            return 'applies_to_locations';
        }

        return 'applies_to_pages';
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
