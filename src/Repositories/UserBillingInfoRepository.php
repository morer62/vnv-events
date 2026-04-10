<?php

namespace App\Repositories; 

class UserBillingInfoRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "user_billing_info";
        $this->db = new Connection();
    }

    public function getByUserId(int $userId): ?array
    {
        $this->db->query("SELECT * FROM user_billing_info WHERE user_id = :user_id LIMIT 1");
        $this->db->bind(":user_id", $userId);
        $result = $this->db->fetchOne();
        return $result ? (array) $result : null;
    }

    public function upsert(int $userId, array $data): void
    {
        $existing = $this->getByUserId($userId);

        if ($existing) {
            $this->db->query("UPDATE user_billing_info 
                SET billing_address_1 = :billing_address_1,
                    billing_address_2 = :billing_address_2,
                    billing_city = :billing_city,
                    billing_state = :billing_state,
                    billing_zip = :billing_zip,
                    updated_at = NOW()
                WHERE user_id = :user_id");
        } else {
            $this->db->query("INSERT INTO user_billing_info 
                (
                    billing_address_1,
                    billing_address_2,
                    billing_city,
                    billing_state,
                    billing_zip,
                    user_id,
                    created_at,
                    updated_at
                ) 
                VALUES (
                    :billing_address_1,
                    :billing_address_2,
                    :billing_city,
                    :billing_state,
                    :billing_zip,
                    :user_id,
                    NOW(),
                    NOW()
                )");
        }

        $this->db->bind(":billing_address_1", $data['billing_address_1'] ?? null);
        $this->db->bind(":billing_address_2", $data['billing_address_2'] ?? null);
        $this->db->bind(":billing_city", $data['billing_city'] ?? null);
        $this->db->bind(":billing_state", $data['billing_state'] ?? null);
        $this->db->bind(":billing_zip", $data['billing_zip'] ?? null);
        $this->db->bind(":user_id", $userId);
        $this->db->execute();
    }
}