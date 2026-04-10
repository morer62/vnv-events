<?php

namespace App\Repositories;

class StoreCouponCustomersRepository extends BaseRepository
{
    protected array $fields = [
        'id',
        'id_coupon',
        'id_user',
        'email',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_coupon_customers";
        $this->db = new Connection();
    }

    public function getByCoupon(int $couponId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_coupon = :id_coupon
            ORDER BY id DESC
        ");
        $this->db->bind(':id_coupon', $couponId);
        return $this->db->fetchAll();
    }

    public function isAllowedForCoupon(int $couponId, ?int $userId, ?string $email): bool
    {
        $email = strtolower(trim((string)$email));
        $userId = (int)$userId;

        $sql = "
            SELECT id
            FROM {$this->table}
            WHERE id_coupon = :id_coupon
        ";

        if ($userId > 0 && $email !== '') {
            $sql .= " AND (id_user = :id_user OR LOWER(email) = :email)";
        } elseif ($userId > 0) {
            $sql .= " AND id_user = :id_user";
        } elseif ($email !== '') {
            $sql .= " AND LOWER(email) = :email";
        } else {
            return false;
        }

        $sql .= " LIMIT 1";
        $this->db->query($sql);
        $this->db->bind(':id_coupon', $couponId);
        if ($userId > 0) {
            $this->db->bind(':id_user', $userId);
        }
        if ($email !== '') {
            $this->db->bind(':email', $email);
        }

        return (bool)$this->db->fetchOne();
    }

    public function deleteByCoupon(int $couponId): bool
    {
        $this->db->query("DELETE FROM {$this->table} WHERE id_coupon = :id_coupon");
        $this->db->bind(':id_coupon', $couponId);
        $this->db->execute();
        return true;
    }
}

