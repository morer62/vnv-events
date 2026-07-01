<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;
use PDOException;

/**
 * Stores payment provider credentials per owner.
 *
 * NOTE: This repo encrypts sensitive fields at rest.
 */
class PaymentProvidersRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    protected array $fields = [
        'id_owner',
        'site_key',
        'provider_type',
        'provider_name',
        'api_key',
        'api_secret',
        'public_key',
        'webhook_secret',
        'environment',
        'currency',
        'merchant_email',
        'location_id',
        'is_active',
        'is_verified',
        'is_default',
        'last_used_at',
        'created_at',
        'updated_at'
    ];

    private const ENCRYPTION_METHOD = 'AES-256-CBC';
    private const SENSITIVE_FIELDS = ['api_key', 'api_secret', 'webhook_secret'];

    public function __construct()
    {
        $this->table = "payment_providers_credentials";
        $this->db = new Connection();
    }

    private function getEncryptionKey(): string
    {
        $key = $_ENV['PAYMENT_ENCRYPTION_KEY'] ?? null;
        if (!$key) {
            $key = $_ENV['VNV_SECRET_KEY'] ?? 'default-insecure-key-change-this';
        }
        return hash('sha256', $key, true);
    }

    private function encrypt(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $key = $this->getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::ENCRYPTION_METHOD));
        $encrypted = openssl_encrypt($data, self::ENCRYPTION_METHOD, $key, 0, $iv);
        return base64_encode($iv . ($encrypted ?: ''));
    }

    private function decrypt(string $data): string
    {
        if ($data === '') {
            return '';
        }
        try {
            $key = $this->getEncryptionKey();
            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                return '';
            }
            $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
            $iv = substr($decoded, 0, $ivLength);
            $encrypted = substr($decoded, $ivLength);
            $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_METHOD, $key, 0, $iv);
            return $decrypted !== false ? $decrypted : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function encryptCredentials(array $data): array
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $data[$field] = $this->encrypt((string)$data[$field]);
            }
        }
        return $data;
    }

    private function decryptCredentials(object $provider): object
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            if (isset($provider->$field) && $provider->$field !== '') {
                $provider->$field = $this->decrypt((string)$provider->$field);
            }
        }
        return $provider;
    }

    public function add(array $data): bool
    {
        $data = $this->encryptCredentials($data);
        if (empty($data['site_key'])) {
            $data['site_key'] = 'global';
        }
        return parent::add($data);
    }

    public function update(array $data, array $criteriaVals): bool
    {
        $data = $this->encryptCredentials($data);
        return parent::update($data, $criteriaVals);
    }

    public function getAllByOwner(int $ownerId, int $page = 1, int $perPage = 50, ?string $siteKey = null): array
    {
        try {
            $offset = max(0, ($page - 1) * $perPage);
            $this->db->query("
                SELECT SQL_CALC_FOUND_ROWS *
                FROM `{$this->table}`
                WHERE `id_owner` = :owner_id
                ORDER BY `is_default` DESC, `is_active` DESC, `provider_type`, `provider_name`
                LIMIT :limit OFFSET :offset
            ");
            $this->db->bind(':owner_id', $ownerId);
            $this->db->bind(':limit', $perPage, \PDO::PARAM_INT);
            $this->db->bind(':offset', $offset, \PDO::PARAM_INT);
            $rows = $this->db->fetchAll();

            $this->db->query("SELECT FOUND_ROWS() as total");
            $totalRow = $this->db->fetchOne();
            $total = (int)($totalRow->total ?? 0);

            $rows = array_map(fn($p) => $this->decryptCredentials($p), $rows);
            return ['data' => $rows, 'total' => $total];
        } catch (PDOException $e) {
            return ['data' => [], 'total' => 0];
        }
    }

    public function getById(int $id, int $ownerId): ?object
    {
        try {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE `id` = :id AND `id_owner` = :owner_id
                LIMIT 1
            ");
            $this->db->bind(':id', $id);
            $this->db->bind(':owner_id', $ownerId);
            $row = $this->db->fetchOne();
            return $row ? $this->decryptCredentials($row) : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public function deactivateAllByOwner(int $ownerId, ?string $siteKey = null): bool
    {
        $this->db->query("UPDATE `{$this->table}` SET `is_active` = 0 WHERE `id_owner` = :owner_id");
        $this->db->bind(':owner_id', $ownerId);
        return (bool)$this->db->execute();
    }

    public function setDefault(int $ownerId, int $providerId, ?string $siteKey = null): bool
    {
        $this->db->query("UPDATE `{$this->table}` SET `is_default` = 0 WHERE `id_owner` = :owner_id");
        $this->db->bind(':owner_id', $ownerId);
        $this->db->execute();

        $this->db->query("UPDATE `{$this->table}` SET `is_default` = 1 WHERE `id_owner` = :owner_id AND `id` = :id");
        $this->db->bind(':owner_id', $ownerId);
        $this->db->bind(':id', $providerId);
        return (bool)$this->db->execute();
    }

    public function setActive(int $ownerId, int $providerId, ?string $siteKey = null): bool
    {
        $this->deactivateAllByOwner($ownerId, $siteKey);
        $this->db->query("UPDATE `{$this->table}` SET `is_active` = 1 WHERE `id_owner` = :owner_id AND `id` = :id");
        $this->db->bind(':owner_id', $ownerId);
        $this->db->bind(':id', $providerId);
        return (bool)$this->db->execute();
    }

    public function normalizeSingleActiveAndDefault(int $ownerId): void
    {
        $this->db->query("
            SELECT id
            FROM `{$this->table}`
            WHERE `id_owner` = :owner_id AND `is_active` = 1
            ORDER BY (`environment` = 'production') DESC, `is_default` DESC, `last_used_at` DESC, `updated_at` DESC, `id` DESC
            LIMIT 1
        ");
        $this->db->bind(':owner_id', $ownerId);
        $active = $this->db->fetchOne();
        if ($active && isset($active->id)) {
            $this->db->query("
                UPDATE `{$this->table}`
                SET `is_active` = CASE WHEN `id` = :id THEN 1 ELSE 0 END
                WHERE `id_owner` = :owner_id
            ");
            $this->db->bind(':id', (int)$active->id);
            $this->db->bind(':owner_id', $ownerId);
            $this->db->execute();
        }

        $this->db->query("
            SELECT id
            FROM `{$this->table}`
            WHERE `id_owner` = :owner_id
            ORDER BY `is_active` DESC, (`environment` = 'production') DESC, `is_default` DESC, `updated_at` DESC, `id` DESC
            LIMIT 1
        ");
        $this->db->bind(':owner_id', $ownerId);
        $default = $this->db->fetchOne();
        if ($default && isset($default->id)) {
            $this->db->query("
                UPDATE `{$this->table}`
                SET `is_default` = CASE WHEN `id` = :id THEN 1 ELSE 0 END
                WHERE `id_owner` = :owner_id
            ");
            $this->db->bind(':id', (int)$default->id);
            $this->db->bind(':owner_id', $ownerId);
            $this->db->execute();
        }
    }

    public function getActiveProviderForOwner(int $ownerId, ?string $siteKey = null): ?object
    {
        $this->normalizeSingleActiveAndDefault($ownerId);

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `id_owner` = :owner_id
            ORDER BY `is_default` DESC, `is_active` DESC, `id` DESC
        ");
        $this->db->bind(':owner_id', $ownerId);
        $rows = $this->db->fetchAll();
        foreach ($rows as $row) {
            if ((int)($row->is_active ?? 0) === 1) {
                return $this->decryptCredentials($row);
            }
        }
        return null;
    }

    /**
     * Devuelve el id_owner a usar para cobros (order-access).
     * Si la orden fue creada por un usuario nivel 2 que tiene proveedor configurado, usa ese;
     * si no, usa order.id_owner (nivel 1 / institucion).
     */
    public function getPaymentOwnerIdForOrder(object $order): int
    {
        $ownerId = (int)($order->id_owner ?? 0);
        if (empty($order->id_user)) {
            return $ownerId;
        }

        $userRepo = new UserRepository();
        $creator = $userRepo->getOne(['id' => $order->id_user]);
        if (!$creator || (int)$creator->level !== 2) {
            return $ownerId;
        }

        $provider = $this->getActiveProviderForOwner((int)$order->id_user);
        if ($provider) {
            return (int)$order->id_user;
        }

        return $ownerId;
    }

    public function providerNameExists(int $ownerId, string $providerType, string $providerName): bool
    {
        $this->db->query("
            SELECT id
            FROM `{$this->table}`
            WHERE `id_owner` = :owner_id AND `provider_type` = :type AND `provider_name` = :name
            LIMIT 1
        ");
        $this->db->bind(':owner_id', $ownerId);
        $this->db->bind(':type', $providerType);
        $this->db->bind(':name', $providerName);
        return (bool)$this->db->fetchOne();
    }
}

