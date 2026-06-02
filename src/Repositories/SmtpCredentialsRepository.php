<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;
use PDO;
use PDOException;

class SmtpCredentialsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    protected string $table = "smtp_credentials";

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'provider_name',
        'provider_type',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'from_email',
        'from_name',
        'reply_to_email',
        'is_active',
        'is_verified',
        'is_default',
        'last_used_at',
        'last_error',
        'created_at',
        'updated_at'
    ];

    private const ENCRYPTION_METHOD = 'AES-256-CBC';

    public function __construct()
    {
        $this->db = new Connection();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_owner INT NOT NULL,
                site_key VARCHAR(80) NULL,
                provider_name VARCHAR(120) NOT NULL,
                provider_type VARCHAR(40) NOT NULL DEFAULT 'custom',
                smtp_host VARCHAR(255) NOT NULL,
                smtp_port INT NOT NULL DEFAULT 587,
                smtp_encryption VARCHAR(20) NOT NULL DEFAULT 'tls',
                smtp_username VARCHAR(255) NOT NULL,
                smtp_password TEXT NOT NULL,
                from_email VARCHAR(255) NOT NULL,
                from_name VARCHAR(255) NOT NULL,
                reply_to_email VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                last_used_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                INDEX idx_smtp_owner (id_owner),
                INDEX idx_smtp_site (site_key),
                INDEX idx_smtp_owner_site (id_owner, site_key),
                INDEX idx_smtp_active (is_active),
                INDEX idx_smtp_default (is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->db->execute();
    }

    private function getEncryptionKey(): string
    {
        $key = $_ENV['SMTP_ENCRYPTION_KEY'] ?? $_ENV['PAYMENT_ENCRYPTION_KEY'] ?? $_ENV['VNV_SECRET_KEY'] ?? 'default-key';
        return hash('sha256', $key, true);
    }

    private function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        $key = $this->getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::ENCRYPTION_METHOD));
        $encrypted = openssl_encrypt($plain, self::ENCRYPTION_METHOD, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $cipher): string
    {
        if ($cipher === '') return '';
        try {
            $key = $this->getEncryptionKey();
            $raw = base64_decode($cipher);
            $ivLen = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
            $iv = substr($raw, 0, $ivLen);
            $enc = substr($raw, $ivLen);
            $out = openssl_decrypt($enc, self::ENCRYPTION_METHOD, $key, 0, $iv);
            return $out !== false ? $out : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function encryptPasswordInData(array $data): array
    {
        if (isset($data['smtp_password']) && $data['smtp_password'] !== '') {
            $data['smtp_password'] = $this->encrypt((string)$data['smtp_password']);
        }
        return $data;
    }

    private function decryptPasswordInRow(object $row): object
    {
        if (isset($row->smtp_password) && (string)$row->smtp_password !== '') {
            $row->smtp_password = $this->decrypt((string)$row->smtp_password);
        }
        return $row;
    }

    public function add(array $data): bool
    {
        $data = $this->encryptPasswordInData($data);
        $data = $this->withDefaultSiteKey($data);
        return parent::add($data);
    }

    public function update(array $data, array $criteriaVals): bool
    {
        $data = $this->encryptPasswordInData($data);
        return parent::update($data, $criteriaVals);
    }

    public function getById(int $id, int $ownerId): ?object
    {
        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE id = :id AND id_owner = :owner
            LIMIT 1
        ");
        $this->db->bind(':id', $id, PDO::PARAM_INT);
        $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
        $row = $this->db->fetchOne();
        return $row ? $this->decryptPasswordInRow($row) : null;
    }

    public function getConfiguredForOwner(int $ownerId, ?string $siteKey = null): ?object
    {
        try {
            $configuredSmtpId = (int)(new BrandSiteSettingsRepository())->get('active_smtp_id', 0, $siteKey);
            if ($configuredSmtpId > 0) {
                $siteSql = $this->siteScopeSql($siteKey);
                $this->db->query("
                    SELECT *
                    FROM {$this->table}
                    WHERE id = :id
                      AND id_owner = :owner
                      AND is_active = 1
                      {$siteSql}
                    LIMIT 1
                ");
                $this->db->bind(':id', $configuredSmtpId, PDO::PARAM_INT);
                $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
                $this->bindSiteScope($siteKey);
                $row = $this->db->fetchOne();
                if ($row) {
                    return $this->decryptPasswordInRow($row);
                }
            }
        } catch (\Throwable $e) {
            // Fall back to site-scoped default/active selection when settings are unavailable.
        }

        $all = $this->getAllByOwner($ownerId, 1, 200, $siteKey);
        $configs = $all['data'] ?? [];

        foreach ($configs as $cfg) {
            if ((int)($cfg->is_default ?? 0) === 1 && (int)($cfg->is_active ?? 0) === 1) {
                return $cfg;
            }
        }

        foreach ($configs as $cfg) {
            if ((int)($cfg->is_active ?? 0) === 1) {
                return $cfg;
            }
        }

        return null;
    }

    public function getAllByOwner(int $ownerId, int $page = 1, int $limit = 50, ?string $siteKey = null): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE id_owner = :owner
            {$siteSql}
            ORDER BY is_default DESC, is_active DESC, provider_name ASC
            LIMIT :lim OFFSET :off
        ");
        $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);
        $this->db->bind(':lim', $limit, PDO::PARAM_INT);
        $this->db->bind(':off', $offset, PDO::PARAM_INT);
        $rows = $this->db->fetchAll();
        $rows = array_map(fn($r) => $this->decryptPasswordInRow($r), $rows);

        $this->db->query("SELECT COUNT(*) AS total FROM {$this->table} WHERE id_owner = :owner {$siteSql}");
        $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);
        $count = $this->db->fetchOne();

        return [
            'data' => $rows,
            'total' => (int)($count->total ?? 0),
            'current_page' => $page,
            'limit' => $limit
        ];
    }

    public function providerNameExists(int $ownerId, string $providerName, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE id_owner = :owner AND provider_name = :name";
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
        }
        $this->db->query($sql);
        $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
        $this->db->bind(':name', $providerName);
        if ($excludeId !== null) {
            $this->db->bind(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        $row = $this->db->fetchOne();
        return ((int)($row->total ?? 0)) > 0;
    }

    public function setAsDefault(int $smtpId, int $ownerId): bool
    {
        try {
            $this->db->beginTransaction();
            $this->db->query("UPDATE {$this->table} SET is_default = 0 WHERE id_owner = :owner");
            $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
            $this->db->execute();

            $this->db->query("UPDATE {$this->table} SET is_default = 1, updated_at = NOW() WHERE id = :id AND id_owner = :owner");
            $this->db->bind(':id', $smtpId, PDO::PARAM_INT);
            $this->db->bind(':owner', $ownerId, PDO::PARAM_INT);
            $this->db->execute();
            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function activate(int $smtpId, int $ownerId): bool
    {
        return $this->update(['is_active' => 1], ['id' => $smtpId, 'id_owner' => $ownerId]);
    }

    public function deactivate(int $smtpId, int $ownerId): bool
    {
        return $this->update(['is_active' => 0], ['id' => $smtpId, 'id_owner' => $ownerId]);
    }

    public function markAsVerified(int $smtpId, int $ownerId): bool
    {
        return $this->update(['is_verified' => 1], ['id' => $smtpId, 'id_owner' => $ownerId]);
    }

    public function deleteSmtp(int $smtpId, int $ownerId): bool
    {
        return $this->delete(['id' => $smtpId, 'id_owner' => $ownerId]);
    }
}

