<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;
use App\Repositories\Concerns\ContentOriginRepositoryTrait;

class CmsContentsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;
    use ContentOriginRepositoryTrait;

    protected string $table = "cms_contents";

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'content_type',
        'id_template',
        'id_blog_category',
        'id_cms_category',
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

    public function getAllByType(string $type, string $language = 'en', ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey, 'c');
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                t.template_key,
                t.type AS template_type,
                t.preview_html,
                t.template_structure_json,
                t.css_text AS template_css_text,
                t.metadata_json AS template_metadata_json
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.type = :type
              AND c.language = :language
              {$siteSql}
            ORDER BY c.id DESC
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getOneWithTemplate(int $id): ?object
    {
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                t.template_key,
                t.type AS template_type,
                t.preview_html,
                t.template_structure_json,
                t.css_text AS template_css_text,
                t.metadata_json AS template_metadata_json
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

    public function getAllForPanel(string $language = 'en', ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey, 'c');
        $query = "
            SELECT
                c.*,
                t.name AS template_name,
                t.template_key,
                t.type AS template_type,
                t.preview_html,
                t.template_structure_json,
                t.css_text AS template_css_text,
                t.metadata_json AS template_metadata_json
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.language = :language
              {$siteSql}
            ORDER BY c.id DESC
        ";

        $this->db->query($query);
        $this->db->bind(':language', $language);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getBySlugTypeAndLanguage(string $slug, string $type = 'page', string $language = 'en', ?string $siteKey = null): ?object
    {
        $siteSql = $this->publicVisibilitySql('cms_content', $siteKey, 'c');
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                t.template_key,
                t.type AS template_type,
                t.preview_html,
                t.template_structure_json,
                t.css_text AS template_css_text,
                t.metadata_json AS template_metadata_json
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.slug = :slug
              AND c.type = :type
              AND c.language = :language
              {$siteSql}
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':slug', $slug);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);
        $this->bindSiteScope($siteKey);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublishedByType(string $type, string $language = 'en', ?string $siteKey = null): array
    {
        $siteSql = $this->publicVisibilitySql('cms_content', $siteKey, 'c');
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                t.template_key,
                t.type AS template_type,
                t.preview_html,
                t.template_structure_json,
                t.css_text AS template_css_text,
                t.metadata_json AS template_metadata_json
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.type = :type
              AND c.language = :language
              AND c.status = 'PUBLISHED'
              {$siteSql}
            ORDER BY c.published_at DESC, c.id DESC
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getPublishedSitemapEntries(string $language = 'en', ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey, 'c');
        $query = "
            SELECT
                c.id,
                c.type,
                c.content_type,
                c.title,
                c.slug,
                c.canonical_url,
                c.robots,
                c.published_at,
                c.updated_at,
                c.created_at,
                r.route
            FROM `{$this->table}` c
            INNER JOIN `cms_routes` r ON r.id_content = c.id AND r.is_main = 1
            WHERE c.status = 'PUBLISHED'
              AND c.language = :language
              AND c.type IN ('page', 'post')
              AND (c.robots IS NULL OR LOWER(c.robots) NOT LIKE '%noindex%')
              AND (r.status IS NULL OR r.status = 'ACTIVE')
              {$siteSql}
            ORDER BY c.updated_at DESC, c.published_at DESC, c.id DESC
        ";

        $this->db->query($query);
        $this->db->bind(':language', $language);
        $this->bindSiteScope($siteKey);

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
            $query .= " AND (`id_owner` = :id_owner OR `id_owner` IS NULL)";
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

    public function getHomepage(string $language = 'en', ?string $siteKey = null): ?object
    {
        $siteSql = $this->publicVisibilitySql('cms_content', $siteKey, 'c');
        $query = "
            SELECT 
                c.*,
                t.name AS template_name,
                t.template_key,
                t.type AS template_type,
                t.preview_html,
                t.template_structure_json,
                t.css_text AS template_css_text,
                t.metadata_json AS template_metadata_json
            FROM `{$this->table}` c
            LEFT JOIN `cms_templates` t ON t.id = c.id_template
            WHERE c.is_homepage = 1
              AND c.language = :language
              {$siteSql}
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':language', $language);
        $this->bindSiteScope($siteKey);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function countByType(string $type, string $language = 'en'): int
    {
        $siteSql = $this->siteScopeSql();
        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE `type` = :type
              AND `language` = :language
              {$siteSql}
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':language', $language);
        $this->bindSiteScope();

        $result = $this->db->fetchOne();

        return $result ? (int)$result->total : 0;
    }

    public function countByTypeAndStatus(string $type, string $status, string $language = 'en'): int
    {
        $siteSql = $this->siteScopeSql();
        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE `type` = :type
              AND `status` = :status
              AND `language` = :language
              {$siteSql}
        ";

        $this->db->query($query);
        $this->db->bind(':type', $type);
        $this->db->bind(':status', $status);
        $this->db->bind(':language', $language);
        $this->bindSiteScope();

        $result = $this->db->fetchOne();

        return $result ? (int)$result->total : 0;
    }
}
