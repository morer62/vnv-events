<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class CmsTemplatesRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    protected string $table = "cms_templates";

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'name',
        'template_key',
        'description',
        'type',
        'preview_html',
        'template_structure_json',
        'css_text',
        'metadata_json',
        'status',
        'created_at',
        'updated_at',
    ];

    public function add(array $data): bool
    {
        return parent::add($this->filterExistingColumns($this->withDefaultSiteKey($data)));
    }

    public function update(array $data, array $criteriaVals): bool
    {
        return parent::update($this->filterExistingColumns($data), $criteriaVals);
    }

    public function getActive(?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);

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

        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getByTemplateKey(string $templateKey, ?string $siteKey = null): ?object
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $orderSql = $this->hasTemplateColumn('site_key')
            ? "ORDER BY FIELD(site_key, :preferred_site_key, 'shared', 'global', 'all_sites') ASC, id DESC"
            : "ORDER BY id DESC";

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `template_key` = :template_key
            {$siteSql}
            {$orderSql}
            LIMIT 1
        ");
        $normalizedSiteKey = $this->normalizeSiteKey($siteKey);
        $this->db->bind(':template_key', $templateKey);
        $this->bindSiteScope($normalizedSiteKey);
        if ($this->hasTemplateColumn('site_key')) {
            $this->db->bind(':preferred_site_key', $normalizedSiteKey);
        }

        return $this->db->fetchOne() ?: null;
    }

    public function templateKeyExists(string $templateKey, int $excludeId = 0, ?string $siteKey = null, ?int $ownerId = null): bool
    {
        $query = "
            SELECT COUNT(*) as total
            FROM `{$this->table}`
            WHERE `template_key` = :template_key
        ";

        if ($this->hasTemplateColumn('site_key')) {
            $query .= " AND `site_key` = :site_key";
        }

        if ($this->hasTemplateColumn('id_owner')) {
            if ($ownerId === null) {
                $query .= " AND `id_owner` IS NULL";
            } else {
                $query .= " AND (`id_owner` = :owner_id OR `id_owner` IS NULL)";
            }
        }

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':template_key', $templateKey);

        if ($this->hasTemplateColumn('site_key')) {
            $this->db->bind(':site_key', $this->normalizeSiteKey($siteKey));
        }

        if ($this->hasTemplateColumn('id_owner') && $ownerId !== null) {
            $this->db->bind(':owner_id', $ownerId);
        }

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }


    public function getAllForPanel(?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE 1=1
            {$siteSql}
            ORDER BY `created_at` DESC, `id` DESC
        ");

        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function getOneForPanel(int $id, ?string $siteKey = null): ?object
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `id` = :id
            {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':id', $id);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchOne() ?: null;
    }

    public function getByType(string $type, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);

        if ($this->hasStatusColumn()) {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE `type` = :type
                AND `status` = 'ACTIVE'
                {$siteSql}
                ORDER BY `name` ASC
            ");
        } else {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE `type` = :type
                {$siteSql}
                ORDER BY `name` ASC
            ");
        }

        $this->db->bind(':type', $type);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function defaultPreviewHtml(): string
    {
        return '<section class="cms-template-shell"><p class="cms-eyebrow">{{ site_name }}</p><h1>{{ title }}</h1><div class="cms-body">{{ body_html|raw }}</div></section>';
    }

    private function hasStatusColumn(): bool
    {
        return $this->hasTemplateColumn('status');
    }

    private function hasTemplateColumn(string $column): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE :column");
            $this->db->bind(':column', $column);
            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function filterExistingColumns(array $data): array
    {
        return array_filter(
            $data,
            fn($key) => in_array($key, $this->fields, true) && $this->hasTemplateColumn($key),
            ARRAY_FILTER_USE_KEY
        );
    }
}
