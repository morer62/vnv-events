<?php

namespace App\Repositories;

class StoreCouponRedemptionsRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_coupon',
        'id_owner',
        'id_store_order',
        'id_user',
        'email',
        'discount_amount',
        'redeemed_at'
    ];

    public function __construct()
    {
        $this->table = "store_coupon_redemptions";
        $this->db = new Connection();
    }

    public function countByCouponAndCustomer(int $couponId, ?int $userId, ?string $email): int
    {
        $userId = (int)$userId;
        $email = strtolower(trim((string)$email));

        if ($userId <= 0 && $email === '') {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) AS total
            FROM {$this->table}
            WHERE id_coupon = :id_coupon
        ";
        if ($userId > 0 && $email !== '') {
            $sql .= " AND (id_user = :id_user OR LOWER(email) = :email)";
        } elseif ($userId > 0) {
            $sql .= " AND id_user = :id_user";
        } else {
            $sql .= " AND LOWER(email) = :email";
        }

        $this->db->query($sql);
        $this->db->bind(':id_coupon', $couponId);
        if ($userId > 0) {
            $this->db->bind(':id_user', $userId);
        }
        if ($email !== '') {
            $this->db->bind(':email', $email);
        }

        $row = $this->db->fetchOne();
        return (int)($row->total ?? 0);
    }
}

