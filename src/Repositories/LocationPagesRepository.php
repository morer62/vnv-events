<?php

namespace App\Repositories;

class LocationPagesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "cms_location_pages";
        $this->db = new Connection();

        $this->fields = [
            'id',
            'id_owner',
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
            'created_at',
            'updated_at',
        ];
    }

    public function getBySlug(string $slug): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE slug = :slug
            LIMIT 1
        ");
        $this->db->bind(':slug', trim(strtolower($slug)));

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublishedBySlug(string $slug): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE slug = :slug
              AND status = 'PUBLISHED'
            LIMIT 1
        ");
        $this->db->bind(':slug', trim(strtolower($slug)));

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

    public function getAllForPanel(): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            ORDER BY created_at DESC
        ");

        return $this->db->fetchAll() ?: [];
    }

    public function getAllPublished(): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = 'PUBLISHED'
            ORDER BY published_at DESC, created_at DESC
        ");

        return $this->db->fetchAll() ?: [];
    }
}