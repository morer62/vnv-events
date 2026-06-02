<?php

namespace App\Repositories;

use Throwable;

class AiContentSettingsRepository extends BaseRepository
{
    protected string $table = 'ai_content_settings';

    protected array $fields = [
        'id',
        'id_owner',
        'id_user_business',
        'site_key',
        'setting_key',
        'setting_value',
        'created_at',
        'updated_at',
    ];

    private ?bool $tableAvailable = null;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?: new Connection();
    }

    public function tableExists(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            $this->db->query("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$this->table}'
                LIMIT 1
            ");
            $this->tableAvailable = (bool)$this->db->fetchOne();
        } catch (Throwable $e) {
            $this->tableAvailable = false;
        }

        return $this->tableAvailable;
    }

    public function getValue(string $key, ?string $siteKey = null): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }

        $siteKey = $this->normalizeSiteKey($siteKey);

        $this->db->query("
            SELECT setting_value
            FROM {$this->table}
            WHERE setting_key = :setting_key
              AND site_key = :site_key
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':setting_key', $key);
        $this->db->bind(':site_key', $siteKey);

        $row = $this->db->fetchOne();
        return $row ? (string)$row->setting_value : null;
    }

    public function allForSite(?string $siteKey = null): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $siteKey = $this->normalizeSiteKey($siteKey);

        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE site_key = :site_key
            ORDER BY setting_key ASC
        ");
        $this->db->bind(':site_key', $siteKey);

        return $this->db->fetchAll() ?: [];
    }

    public function upsert(string $key, string $value, string $siteKey, int $ownerId, int $businessId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $this->db->query("
            INSERT INTO {$this->table}
                (id_owner, id_user_business, site_key, setting_key, setting_value, created_at, updated_at)
            VALUES
                (:id_owner, :id_user_business, :site_key, :setting_key, :setting_value, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                id_owner = VALUES(id_owner),
                id_user_business = VALUES(id_user_business),
                updated_at = NOW()
        ");
        $this->db->bind(':id_owner', $ownerId);
        $this->db->bind(':id_user_business', $businessId);
        $this->db->bind(':site_key', $this->normalizeSiteKey($siteKey));
        $this->db->bind(':setting_key', $key);
        $this->db->bind(':setting_value', $value);
        $this->db->execute();

        return true;
    }

    private function normalizeSiteKey(?string $siteKey): string
    {
        $siteKey = trim((string)($siteKey ?? ''));
        return $siteKey !== '' ? strtolower($siteKey) : strtolower((string)($_ENV['AI_CONTENT_SITE_KEY'] ?? 'vnv_events'));
    }
}
