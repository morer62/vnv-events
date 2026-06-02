<?php

namespace App\Repositories\Concerns;

use App\Utils\SiteContext;

trait SiteScopedRepositoryTrait
{
    private static array $siteScopeColumnCache = [];

    protected function siteScopeSql(?string $siteKey = null, string $alias = ''): string
    {
        if (!$this->hasSiteScopeColumn()) {
            return '';
        }

        $column = $alias !== '' ? "{$alias}.site_key" : 'site_key';

        return " AND ({$column} = :site_key OR {$column} IN ('shared', 'global', 'all_sites'))";
    }

    protected function publicVisibilitySql(string $entityType, ?string $siteKey = null, string $alias = ''): string
    {
        $siteSql = $this->siteScopeSql($siteKey, $alias);

        if (!$this->hasSiteVisibilityTable()) {
            return $siteSql;
        }

        $idColumn = $alias !== '' ? "{$alias}.id" : 'id';
        $entityType = str_replace("'", "''", $entityType);

        return $siteSql . "
            AND EXISTS (
                SELECT 1
                FROM site_visibility sv
                WHERE sv.site_key = :site_key
                  AND sv.entity_type = '{$entityType}'
                  AND sv.entity_id = {$idColumn}
                  AND sv.is_visible = 1
                  AND sv.visibility_status = 'VISIBLE'
            )
        ";
    }

    protected function bindSiteScope(?string $siteKey = null): void
    {
        if (!$this->hasSiteScopeColumn()) {
            return;
        }

        $this->db->bind(':site_key', $this->normalizeSiteKey($siteKey));
    }

    protected function withDefaultSiteKey(array $data): array
    {
        if ($this->hasSiteScopeColumn() && empty($data['site_key'])) {
            $data['site_key'] = SiteContext::siteKey();
        }

        return $data;
    }

    protected function normalizeSiteKey(?string $siteKey = null): string
    {
        $siteKey = trim((string)($siteKey ?? ''));

        return $siteKey !== '' ? strtolower($siteKey) : SiteContext::siteKey();
    }

    protected function hasSiteScopeColumn(): bool
    {
        $table = (string)($this->table ?? '');
        if ($table === '' || !$this->db) {
            return false;
        }

        if (array_key_exists($table, self::$siteScopeColumnCache)) {
            return self::$siteScopeColumnCache[$table];
        }

        try {
            $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE 'site_key'");
            self::$siteScopeColumnCache[$table] = (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            self::$siteScopeColumnCache[$table] = false;
        }

        return self::$siteScopeColumnCache[$table];
    }

    protected function hasSiteVisibilityTable(): bool
    {
        $cacheKey = '__site_visibility_table';
        if (array_key_exists($cacheKey, self::$siteScopeColumnCache)) {
            return self::$siteScopeColumnCache[$cacheKey];
        }

        try {
            $this->db->query("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'site_visibility'
                LIMIT 1
            ");
            self::$siteScopeColumnCache[$cacheKey] = (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            self::$siteScopeColumnCache[$cacheKey] = false;
        }

        return self::$siteScopeColumnCache[$cacheKey];
    }
}
