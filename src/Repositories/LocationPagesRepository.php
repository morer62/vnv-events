<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;
use App\Repositories\Concerns\ContentOriginRepositoryTrait;

class LocationPagesRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;
    use ContentOriginRepositoryTrait;

    public function __construct()
    {
        $this->table = "cms_location_pages";
        $this->db = new Connection();

        $this->fields = [
            'id',
            'id_owner',
            'site_key',
            'title',
            'slug',
            'category',
            'template_key',
            'city',
            'county',
            'state',
            'hero_title',
            'hero_subtitle',
            'excerpt',
            'content_long',
            'primary_keyword',
            'secondary_keywords',
            'hero_image',
            'gallery_json',
            'faq_json',
            'dynamic_blocks_json',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'og_title',
            'og_description',
            'og_image',
            'canonical_url',
            'schema_json',
            'custom_css',
            'custom_js',
            'is_indexable',
            'status',
            'published_at',
            'content_origin',
            'origin_site_key',
            'created_by',
            'updated_by',
            'origin_metadata_json',
            'created_at',
            'updated_at',
        ];
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getBySlug(string $slug, ?string $siteKey = null): ?object
    {
        $siteSql = $this->publicVisibilitySql('location_page', $siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE slug = :slug
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':slug', trim(strtolower($slug)));
        $this->bindSiteScope($siteKey);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublishedBySlug(string $slug, ?string $siteKey = null): ?object
    {
        $siteSql = $this->publicVisibilitySql('location_page', $siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE slug = :slug
              AND status = 'PUBLISHED'
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':slug', trim(strtolower($slug)));
        $this->bindSiteScope($siteKey);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "
            SELECT id
            FROM {$this->table}
            WHERE slug = :slug
        ";

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
        }

        $sql .= " LIMIT 1";

        $this->db->query($sql);
        $this->db->bind(':slug', trim(strtolower($slug)));

        if ($excludeId) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        return (bool) $this->db->fetchOne();
    }

    public function getAllForPanel(?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE 1=1
              {$siteSql}
            ORDER BY created_at DESC
        ");
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getAllPublished(?string $siteKey = null): array
    {
        $siteSql = $this->publicVisibilitySql('location_page', $siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = 'PUBLISHED'
              {$siteSql}
            ORDER BY published_at DESC, created_at DESC
        ");
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getAllIndexablePublished(?string $siteKey = null): array
    {
        $siteSql = $this->publicVisibilitySql('location_page', $siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = 'PUBLISHED'
              AND is_indexable = 1
              {$siteSql}
            ORDER BY updated_at DESC, published_at DESC, created_at DESC
        ");
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }
}
