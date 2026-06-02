<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StoreSubscriptionsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_PAUSED = 'PAUSED';
    const STATUS_CANCELLED = 'CANCELLED';
    const STATUS_PAYMENT_FAILED = 'PAYMENT_FAILED';
    const STATUS_EXPIRED = 'EXPIRED';

    const FREQUENCY_WEEKLY = 'WEEKLY';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'id_user',
        'id_store_order',
        'archive',
        'coupon_code',
        'id_coupon',
        'email',
        'full_name',
        'phone',
        'city',
        'frequency',
        'status',
        'price_per_meal',
        'minimum_meals',
        'meals_count',
        'next_charge_date',
        'last_charge_date',
        'external_subscription_id',
        'notes',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_subscriptions";
        $this->db = new Connection();
        $this->ensureExtraColumns();
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    private function ensureExtraColumns(): void
    {
        $columns = [
            'archive' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `id_store_order`",
            'coupon_code' => "VARCHAR(80) NULL AFTER `archive`",
            'id_coupon' => "INT(11) NULL AFTER `coupon_code`",
        ];
        foreach ($columns as $col => $def) {
            try {
                $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE '{$col}'");
                if ($this->db->fetchOne()) {
                    continue;
                }
                $this->db->query("ALTER TABLE `{$this->table}` ADD COLUMN `{$col}` {$def}");
                $this->db->execute();
            } catch (\Throwable $e) {
            }
        }
    }

    public function getByEmail(string $email, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE email = :email
              AND COALESCE(archive, 0) = 0
              {$siteSql}
            ORDER BY id DESC
        ");
        $this->db->bind(':email', $email);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll();
    }

    public function getActiveByEmail(string $email, ?int $ownerId = null, ?string $siteKey = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE email = :email
              AND status = :status
              AND COALESCE(archive, 0) = 0
              {$ownerSql}
              {$siteSql}
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':email', $email);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getByUser(int $userId, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_user = :id_user
              AND COALESCE(archive, 0) = 0
              {$siteSql}
            ORDER BY id DESC
        ");
        $this->db->bind(':id_user', $userId);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll();
    }

    public function getActiveByUser(int $userId, ?int $ownerId = null, ?string $siteKey = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_user = :id_user
              AND status = :status
              AND COALESCE(archive, 0) = 0
              {$ownerSql}
              {$siteSql}
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':id_user', $userId);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getDueForCharge(string $date, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = :status
              AND COALESCE(archive, 0) = 0
              AND next_charge_date IS NOT NULL
              AND next_charge_date <= :charge_date
              {$siteSql}
            ORDER BY next_charge_date ASC
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $this->db->bind(':charge_date', $date);
        $this->bindSiteScope($siteKey);

        return $this->db->fetchAll();
    }

    public function assignUser(int $subscriptionId, int $userId): bool
    {
        return $this->update([
            'id_user' => $userId,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $subscriptionId
        ]);
    }

    public function pause(int $subscriptionId): bool
    {
        return $this->update([
            'status' => self::STATUS_PAUSED,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $subscriptionId
        ]);
    }

    public function activate(int $subscriptionId): bool
    {
        return $this->update([
            'status' => self::STATUS_ACTIVE,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $subscriptionId
        ]);
    }

    public function cancel(int $subscriptionId): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $subscriptionId
        ]);
    }

    public function updateNextChargeDate(int $subscriptionId, ?string $nextChargeDate): bool
    {
        return $this->update([
            'next_charge_date' => $nextChargeDate,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $subscriptionId
        ]);
    }

    public function updateMealsCount(int $subscriptionId, int $mealsCount): bool
    {
        return $this->update([
            'meals_count' => max(0, $mealsCount),
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $subscriptionId
        ]);
    }

    public function registerCharge(int $subscriptionId, string $lastChargeDate, ?string $nextChargeDate = null): bool
    {
        $data = [
            'last_charge_date' => $lastChargeDate,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($nextChargeDate !== null) {
            $data['next_charge_date'] = $nextChargeDate;
        }

        return $this->update($data, ['id' => $subscriptionId]);
    }

    public function getFullSubscriptionDetails(int $subscriptionId): ?object
    {
        try {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE id = :id
                LIMIT 1
            ");
            $this->db->bind(':id', $subscriptionId);
            $subscription = $this->db->fetchOne();

            if (!$subscription) {
                return null;
            }

            $itemsRepo = new StoreSubscriptionItemsRepository();
            $subscription->items = $itemsRepo->getBySubscription($subscriptionId);

            return $subscription;
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return null;
        }
    }

    public function getAllByUser(int $userId, int $limit = 100, ?int $ownerId = null, ?string $siteKey = null): array
{
    $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
    $siteSql = $this->siteScopeSql($siteKey);
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE id_user = :id_user
          AND COALESCE(archive, 0) = 0
          {$ownerSql}
          {$siteSql}
        ORDER BY id DESC
        LIMIT :limit
    ");
    $this->db->bind(':id_user', $userId);
    if ($ownerSql !== '') {
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
    }
    $this->bindSiteScope($siteKey);
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll();
}

public function getAllByEmail(string $email, int $limit = 100, ?int $ownerId = null, ?string $siteKey = null): array
{
    $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
    $siteSql = $this->siteScopeSql($siteKey);
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE email = :email
          AND COALESCE(archive, 0) = 0
          {$ownerSql}
          {$siteSql}
        ORDER BY id DESC
        LIMIT :limit
    ");
    $this->db->bind(':email', $email);
    if ($ownerSql !== '') {
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
    }
    $this->bindSiteScope($siteKey);
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll();
}

public function archive(int $subscriptionId): bool
{
    return $this->update([
        'archive' => 1,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $subscriptionId
    ]);
}
    
}
