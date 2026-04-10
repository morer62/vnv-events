<?php

namespace App\Repositories;

class StoreCouponsRepository extends BaseRepository
{
    const SCOPE_GLOBAL = 'GLOBAL';
    const SCOPE_CUSTOMER = 'CUSTOMER';

    const PURCHASE_MODE_SUBSCRIPTION = 'SUBSCRIPTION';
    const PURCHASE_MODE_PAYG = 'PAYG';

    const TYPE_PERCENT = 'PERCENT';
    const TYPE_FIXED = 'FIXED';

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';

    protected array $fields = [
        'id',
        'id_owner',
        'code',
        'scope',
        'purchase_mode',
        'discount_type',
        'discount_value',
        'status',
        'starts_at',
        'expires_at',
        'max_total_uses',
        'total_uses',
        'max_uses_per_customer',
        'min_order_total',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_coupons";
        $this->db = new Connection();
    }

    public function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function getByOwnerAndCode(int $ownerId, string $code): ?object
    {
        $code = $this->normalizeCode($code);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
              AND code = :code
            LIMIT 1
        ");
        $this->db->bind(':id_owner', $ownerId);
        $this->db->bind(':code', $code);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getAllByOwner(int $ownerId, int $limit = 300): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }

    public function incrementTotalUsesAtomic(int $couponId): bool
    {
        $this->db->query("
            UPDATE {$this->table}
            SET total_uses = total_uses + 1,
                updated_at = :updated_at
            WHERE id = :id
              AND (max_total_uses <= 0 OR total_uses < max_total_uses)
        ");
        $this->db->bind(':updated_at', date('Y-m-d H:i:s'));
        $this->db->bind(':id', $couponId);
        $this->db->execute();
        return $this->db->rowCount() > 0;
    }
}

