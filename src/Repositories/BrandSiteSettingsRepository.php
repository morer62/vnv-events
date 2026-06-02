<?php

namespace App\Repositories;

use App\Utils\SiteContext;

class BrandSiteSettingsRepository extends BaseRepository
{
    protected string $table = 'brand_site_settings';

    protected array $fields = [
        'id',
        'site_key',
        'id_user_business',
        'setting_key',
        'setting_value',
        'created_at',
        'updated_at',
    ];

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function get(string $key, mixed $default = null, ?string $siteKey = null): mixed
    {
        $row = $this->find($key, $siteKey);

        return $row ? $row->setting_value : $default;
    }

    public function find(string $key, ?string $siteKey = null): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE site_key = :site_key
              AND setting_key = :setting_key
            LIMIT 1
        ");
        $this->db->bind(':site_key', $siteKey ?: SiteContext::siteKey());
        $this->db->bind(':setting_key', $key);

        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function allForSite(?string $siteKey = null): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE site_key = :site_key
            ORDER BY setting_key ASC
        ");
        $this->db->bind(':site_key', $siteKey ?: SiteContext::siteKey());

        return $this->db->fetchAll() ?: [];
    }

    public function upsert(string $key, mixed $value, ?string $siteKey = null, ?int $ownerId = null): bool
    {
        $this->db->query("
            INSERT INTO {$this->table}
                (site_key, id_user_business, setting_key, setting_value, created_at, updated_at)
            VALUES
                (:site_key, :id_user_business, :setting_key, :setting_value, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                id_user_business = VALUES(id_user_business),
                setting_value = VALUES(setting_value),
                updated_at = NOW()
        ");
        $this->db->bind(':site_key', $siteKey ?: SiteContext::siteKey());
        $this->db->bind(':id_user_business', $ownerId ?: SiteContext::businessOwnerId());
        $this->db->bind(':setting_key', $key);
        $this->db->bind(':setting_value', is_scalar($value) ? (string)$value : json_encode($value));

        return (bool)$this->db->execute();
    }
}
