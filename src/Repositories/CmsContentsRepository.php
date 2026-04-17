<?php

namespace App\Repositories;

class CmsContentsRepository extends BaseRepository
{
    protected string $table = "cms_contents";

    protected array $fields = [
        'id',
        'id_owner',
        'id_template',
        'id_blog_category',
        'type',
        'title',
        'slug',
        'language',
        'content_mode',
        'excerpt',
        'content_json',
        'body_html',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'robots',
        'schema_json',
        'featured_image_url',
        'status',
        'is_homepage',
        'published_at',
        'last_generated_at',
        'created_at',
        'updated_at',
    ];

    public function getAllByType(string $type, string $language = 'en'): array
    {
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                NULL AS template_key,
                NULL AS template_type
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.type = :type
              AND c.language = :language
            ORDER BY c.id DESC
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);

        return $this->db->fetchAll() ?: [];
    }

    public function getOneWithTemplate(int $id): ?object
    {
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                NULL AS template_key,
                NULL AS template_type,
                NULL AS preview_html,
                NULL AS template_structure_json
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.id = :id
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':id', $id);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getBySlugTypeAndLanguage(string $slug, string $type = 'page', string $language = 'en'): ?object
    {
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                NULL AS template_key,
                NULL AS template_type
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.slug = :slug
              AND c.type = :type
              AND c.language = :language
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':slug', $slug);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublishedByType(string $type, string $language = 'en'): array
    {
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                NULL AS template_key,
                NULL AS template_type
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.type = :type
              AND c.language = :language
              AND c.status = 'PUBLISHED'
            ORDER BY c.published_at DESC, c.id DESC
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);

        return $this->db->fetchAll() ?: [];
    }

    public function slugExists(string $slug, ?int $ownerId = null, string $language = 'en', int $excludeId = 0): bool
    {
        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE `slug` = :slug
              AND `language` = :language
        ";

        if ($ownerId === null) {
            $query .= " AND `id_owner` IS NULL";
        } else {
            $query .= " AND `id_owner` = :id_owner";
        }

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':slug', $slug);
        $this->db->bind(':language', $language);

        if ($ownerId !== null) {
            $this->db->bind(':id_owner', $ownerId);
        }

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }

    public function getHomepage(string $language = 'en'): ?object
    {
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                NULL AS template_key,
                NULL AS template_type
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.is_homepage = 1
              AND c.language = :language
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':language', $language);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function countByType(string $type, string $language = 'en'): int
    {
        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE `type` = :type
              AND `language` = :language
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);

        $result = $this->db->fetchOne();

        return $result ? (int)$result->total : 0;
    }

    public function countByTypeAndStatus(string $type, string $status, string $language = 'en'): int
    {
        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE `type` = :type
              AND `status` = :status
              AND `language` = :language
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':status', $status);
        $this->db->bind(':language', $language);

        $result = $this->db->fetchOne();

        return $result ? (int)$result->total : 0;
    }
}