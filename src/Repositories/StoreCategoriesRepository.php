<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StoreCategoriesRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'name',
        'slug',
        'description',
        'icon',
        'meta_title',
        'meta_description',
        'page_builder_json',
        'status',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_categories";
        $this->db = new Connection();
        $this->ensureContentColumns();
    }

    public function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value)));
        $slug = trim($slug, '-');
        return $slug;
    }

    public function generateUniqueSlug(string $baseValue, int $excludeId = 0): string
    {
        $slug = $this->normalizeSlug($baseValue);
        if ($slug === '') {
            $slug = 'category';
        }

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug, int $excludeId = 0): bool
    {
        $query = "SELECT id FROM {$this->table} WHERE slug = :slug";
        if ($excludeId > 0) {
            $query .= " AND id != :exclude_id";
        }
        $query .= " LIMIT 1";
        $this->db->query($query);
        $this->db->bind(':slug', $slug);
        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }
        return $this->db->fetchOne() !== false;
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function debugPublicCategoryLookup(string $slug, ?int $ownerId = null, ?string $siteKey = null): array
    {
        $normalizedSiteKey = $this->normalizeSiteKey($siteKey);
        $debug = [
            'repository_debug_version' => 'store-categories-public-debug-2026-06-14-01',
            'input' => [
                'slug' => $slug,
                'owner_id' => $ownerId,
                'site_key' => $normalizedSiteKey,
            ],
            'schema' => [
                'has_site_scope_column' => $this->hasSiteScopeColumn(),
                'has_site_visibility_table' => $this->hasSiteVisibilityTable(),
            ],
            'database' => [],
            'category_raw' => null,
            'visibility_rows_for_category' => [],
            'exact_public_filter_match' => null,
            'collations' => [],
        ];

        try {
            $this->db->query("SELECT DATABASE() AS database_name, @@collation_connection AS collation_connection");
            $debug['database'] = (array)($this->db->fetchOne() ?: []);
        } catch (\Throwable $e) {
            $debug['database_error'] = $e->getMessage();
        }

        try {
            $this->db->query("
                SELECT id, slug, site_key, id_owner, status
                FROM {$this->table}
                WHERE slug = :slug
                LIMIT 5
            ");
            $this->db->bind(':slug', $slug);
            $rows = $this->db->fetchAll() ?: [];
            $debug['category_raw'] = array_map('get_object_vars', $rows);
            $category = $rows[0] ?? null;
        } catch (\Throwable $e) {
            $debug['category_raw_error'] = $e->getMessage();
            $category = null;
        }

        if ($category && isset($category->id)) {
            try {
                $this->db->query("
                    SELECT id, site_key, entity_type, entity_id, id_user_business, is_visible, visibility_status
                    FROM site_visibility
                    WHERE entity_id = :entity_id
                      AND entity_type = 'store_category'
                    ORDER BY site_key, id
                ");
                $this->db->bind(':entity_id', (int)$category->id, \PDO::PARAM_INT);
                $debug['visibility_rows_for_category'] = array_map('get_object_vars', $this->db->fetchAll() ?: []);
            } catch (\Throwable $e) {
                $debug['visibility_rows_error'] = $e->getMessage();
            }

            try {
                $this->db->query("
                    SELECT sc.id
                    FROM {$this->table} sc
                    WHERE sc.id = :category_id
                      AND sc.status = 'ACTIVE'
                      AND sc.id_owner = :id_owner
                      AND (sc.site_key = :site_key OR sc.site_key IN ('shared', 'global', 'all_sites'))
                      AND EXISTS (
                          SELECT 1
                          FROM site_visibility sv
                          WHERE sv.site_key = :visibility_site_key
                            AND sv.entity_type = 'store_category'
                            AND sv.entity_id = sc.id
                            AND sv.is_visible = 1
                            AND sv.visibility_status = 'VISIBLE'
                      )
                    LIMIT 1
                ");
                $this->db->bind(':category_id', (int)$category->id, \PDO::PARAM_INT);
                $this->db->bind(':id_owner', (int)$ownerId, \PDO::PARAM_INT);
                $this->db->bind(':site_key', $normalizedSiteKey);
                $this->db->bind(':visibility_site_key', $normalizedSiteKey);
                $debug['exact_public_filter_match'] = (bool)$this->db->fetchOne();
            } catch (\Throwable $e) {
                $debug['exact_public_filter_error'] = $e->getMessage();
            }
        }

        try {
            $this->db->query("SHOW FULL COLUMNS FROM {$this->table} WHERE Field IN ('slug', 'site_key', 'status')");
            $debug['collations']['store_categories'] = array_map('get_object_vars', $this->db->fetchAll() ?: []);

            $this->db->query("SHOW FULL COLUMNS FROM site_visibility WHERE Field IN ('site_key', 'entity_type', 'visibility_status')");
            $debug['collations']['site_visibility'] = array_map('get_object_vars', $this->db->fetchAll() ?: []);
        } catch (\Throwable $e) {
            $debug['collations_error'] = $e->getMessage();
        }

        return $debug;
    }

    public function getBySlug(string $slug, ?int $ownerId = null, ?string $siteKey = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("SELECT * FROM {$this->table} WHERE slug = :slug {$ownerSql} {$siteSql} LIMIT 1");
        $this->db->bind(':slug', $slug);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublicBySlug(string $slug, ?int $ownerId = null, ?string $siteKey = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND sc.id_owner = :id_owner" : "";
        $siteSql = $this->publicVisibilitySql('store_category', $siteKey, 'sc');
        $this->db->query("
            SELECT sc.*
            FROM {$this->table} sc
            WHERE sc.slug = :slug
              AND sc.status = :status
              {$ownerSql}
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':slug', $slug);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getActive(?int $ownerId = null, ?string $siteKey = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql($siteKey);

        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = :status
              {$ownerSql}
              {$siteSql}
            ORDER BY name ASC
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getPublicSitemapEntries(int $limit = 1000, ?int $ownerId = null, ?string $siteKey = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND sc.id_owner = :id_owner" : "";
        $siteSql = $this->publicVisibilitySql('store_category', $siteKey, 'sc');
        $this->db->query("
            SELECT sc.id, sc.name, sc.slug, sc.description, sc.meta_title, sc.meta_description, sc.updated_at, sc.created_at
            FROM {$this->table} sc
            WHERE sc.status = :status
              AND sc.slug IS NOT NULL
              AND sc.slug != ''
              {$ownerSql}
              {$siteSql}
            ORDER BY sc.updated_at DESC, sc.created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll() ?: [];
    }

    private function ensureContentColumns(): void
    {
        $columnsToEnsure = [
            'slug' => "ALTER TABLE {$this->table} ADD COLUMN slug VARCHAR(255) NULL AFTER name",
            'meta_title' => "ALTER TABLE {$this->table} ADD COLUMN meta_title VARCHAR(255) NULL AFTER icon",
            'meta_description' => "ALTER TABLE {$this->table} ADD COLUMN meta_description TEXT NULL AFTER meta_title",
            'page_builder_json' => "ALTER TABLE {$this->table} ADD COLUMN page_builder_json LONGTEXT NULL AFTER meta_description",
        ];

        foreach ($columnsToEnsure as $column => $sql) {
            if (!$this->hasColumn($column)) {
                $this->db->query($sql);
                $this->db->execute();
            }
        }
    }

    private function hasColumn(string $column): bool
    {
        $this->db->query("SHOW COLUMNS FROM {$this->table} LIKE :column_name");
        $this->db->bind(':column_name', $column);
        return $this->db->fetchOne() !== false;
    }
}
