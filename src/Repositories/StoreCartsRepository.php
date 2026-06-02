<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StoreCartsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_ABANDONED = 'ABANDONED';
    const STATUS_CONVERTED = 'CONVERTED';
    const STATUS_EXPIRED = 'EXPIRED';

    const PRICING_PAYG = 'PAYG';
    const PRICING_SUBSCRIPTION = 'SUBSCRIPTION';
    const PRICING_QUOTE = 'QUOTE';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'id_user',
        'session_token',
        'recovery_token',
        'guest_name',
        'guest_email',
        'guest_phone',
        'city',
        'audience_type',
        'meal_style',
        'pricing_mode',
        'items_count',
        'meals_count',
        'subtotal',
        'discount',
        'total',
        'status',
        'last_step',
        'abandoned_email_sent',
        'created_at',
        'updated_at',
        'last_activity_at'
    ];

    public function __construct()
    {
        $this->table = "store_carts";
        $this->db = new Connection();
    }

    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getBySessionToken(string $sessionToken, ?int $ownerId = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql();
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE session_token = :session_token
              {$ownerSql}
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':session_token', $sessionToken);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope();
        $result = $this->db->fetchOne();

        return $result ?: null;
    }

    public function getByRecoveryToken(string $recoveryToken, ?int $ownerId = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql();
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE recovery_token = :recovery_token
              {$ownerSql}
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':recovery_token', $recoveryToken);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope();
        $result = $this->db->fetchOne();

        return $result ?: null;
    }

    public function getActiveCartByEmail(string $email, ?int $ownerId = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql();
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE guest_email = :guest_email
              AND status = :status
              {$ownerSql}
              {$siteSql}
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':guest_email', $email);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope();
        $result = $this->db->fetchOne();

        return $result ?: null;
    }

    public function touchCart(int $cartId, ?string $lastStep = null): bool
    {
        $data = [
            'updated_at' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s')
        ];

        if ($lastStep !== null) {
            $data['last_step'] = $lastStep;
        }

        return $this->update($data, ['id' => $cartId]);
    }

    public function markAsAbandoned(int $cartId): bool
    {
        return $this->update([
            'status' => self::STATUS_ABANDONED,
            'updated_at' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $cartId
        ]);
    }

    public function markAsConverted(int $cartId): bool
    {
        return $this->update([
            'status' => self::STATUS_CONVERTED,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $cartId
        ]);
    }

    public function markRecoveryEmailSent(int $cartId): bool
    {
        return $this->update([
            'abandoned_email_sent' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $cartId
        ]);
    }

    public function updateSummary(
        int $cartId,
        int $itemsCount,
        int $mealsCount,
        float $subtotal,
        float $discount,
        float $total
    ): bool {
        return $this->update([
            'items_count' => $itemsCount,
            'meals_count' => $mealsCount,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'updated_at' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $cartId
        ]);
    }

    public function getAbandonedCartsToRecover(int $minutes = 60): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = :status
              AND abandoned_email_sent = 0
              AND guest_email IS NOT NULL
              AND guest_email != ''
              AND last_activity_at IS NOT NULL
              AND last_activity_at <= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
            ORDER BY last_activity_at ASC
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $this->db->bind(':minutes', $minutes, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getActiveOrRecoverableBySessionOrEmail(?string $sessionToken, ?string $email): ?object
    {
        if ($sessionToken) {
            $cart = $this->getBySessionToken($sessionToken);
            if ($cart && in_array($cart->status, [self::STATUS_ACTIVE, self::STATUS_ABANDONED])) {
                return $cart;
            }
        }

        if ($email) {
            $siteSql = $this->siteScopeSql();
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE guest_email = :guest_email
                  AND status IN ('ACTIVE','ABANDONED')
                  {$siteSql}
                ORDER BY id DESC
                LIMIT 1
            ");
            $this->db->bind(':guest_email', $email);
            $this->bindSiteScope();
            $result = $this->db->fetchOne();

            return $result ?: null;
        }

        return null;
    }

    public function getAllByOwner(int $ownerId, int $limit = 100, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
            {$siteSql}
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId);
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getAbandonedByOwner(int $ownerId, int $limit = 100, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
            {$siteSql}
            AND status IN ('ACTIVE','ABANDONED')
            AND guest_email IS NOT NULL
            AND guest_email != ''
            AND meals_count > 0
            ORDER BY last_activity_at DESC, id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId);
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getDetailedCart(int $cartId): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $cartId);
        $cart = $this->db->fetchOne();

        if (!$cart) {
            return null;
        }

        $itemsRepo = new StoreCartItemsRepository();
        $cart->items = $itemsRepo->getDetailedByCart($cartId);

        return $cart;
    }


    public function getPendingRecoveryCarts(int $limit = 10, int $minutes = 30): array
{
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE status IN ('ACTIVE','ABANDONED')
          AND abandoned_email_sent = 0
          AND guest_email IS NOT NULL
          AND guest_email != ''
          AND recovery_token IS NOT NULL
          AND recovery_token != ''
          AND meals_count >= 1
          AND last_activity_at IS NOT NULL
          AND last_activity_at <= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
        ORDER BY last_activity_at ASC
        LIMIT :limit
    ");
    $this->db->bind(':minutes', $minutes, \PDO::PARAM_INT);
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll();
}

public function markAsAbandonedAndRecoverySent(int $cartId): bool
{
    return $this->update([
        'status' => self::STATUS_ABANDONED,
        'abandoned_email_sent' => 1,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $cartId
    ]);
}
}
