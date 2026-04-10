<?php

namespace App\Repositories;

class CmsPagesRepository extends BaseRepository
{
    protected string $table = "cms_pages";

    protected array $fields = [
        'id',
        'id_category',
        'id_template',
        'title',
        'slug',
        'short_description',
        'status',
        'template_source',
        'custom_css',
        'schema_markup',
        'custom_head',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'meta_thumbnail',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'robots_index',
        'robots_follow',
        'published_path',
        'last_published_at',
        'created_at',
        'updated_at',
    ];

    public function getBySlugAndCategory(int $idCategory, string $slug): ?object
    {
        return $this->getOne([
            'id_category' => $idCategory,
            'slug' => $slug
        ]);
    }

    public function slugExists(int $idCategory, string $slug, int $excludeId = 0): bool
    {
        $query = "SELECT COUNT(*) as total 
                  FROM `{$this->table}` 
                  WHERE `id_category` = :id_category 
                  AND `slug` = :slug";

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':id_category', $idCategory);
        $this->db->bind(':slug', $slug);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }

    public function getAllWithCategoryAndTemplate(): array
    {
        $query = "
            SELECT 
                p.*,
                c.name AS category_name,
                c.slug AS category_slug,
                t.name AS template_name,
                t.slug AS template_slug
            FROM `{$this->table}` p
            INNER JOIN `cms_categories` c ON c.id = p.id_category
            LEFT JOIN `cms_templates` t ON t.id = p.id_template
            ORDER BY p.id DESC
        ";

        $this->db->query($query);
        return $this->db->fetchAll();
    }

    public function getOneWithCategoryAndTemplate(int $id): ?object
    {
        $query = "
            SELECT 
                p.*,
                c.name AS category_name,
                c.slug AS category_slug,
                t.name AS template_name,
                t.slug AS template_slug
            FROM `{$this->table}` p
            INNER JOIN `cms_categories` c ON c.id = p.id_category
            LEFT JOIN `cms_templates` t ON t.id = p.id_template
            WHERE p.id = :id
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':id', $id);

        $result = $this->db->fetchOne();

        return $result ?: null;
    }

    public function getPublished(): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `status` = 'PUBLISHED' ORDER BY `id` DESC");
        return $this->db->fetchAll();
    }

    public function getDrafts(): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `status` = 'DRAFT' ORDER BY `id` DESC");
        return $this->db->fetchAll();
    }
}