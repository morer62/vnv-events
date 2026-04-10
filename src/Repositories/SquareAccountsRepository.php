<?php

namespace App\Repositories;

class SquareAccountsRepository extends BaseRepository
{
    protected array $fields = [
        'id_user',
        'square_account_id',
        'is_verified',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted',
        'express_enabled',
        'onboarded_at',
        'created_at',
        'updated_at'
    ];


    public function __construct()
    {
        $this->table = "square_accounts";
        $this->db = new Connection();
    }

    public function getByUser(int $userId): ?object
    {
        return $this->getOne(['id_user' => $userId]);
    }

    public function markAsVerified(int $userId): bool
    {
        return $this->update([
            "is_verified" => 1,
            "updated_at" => date("Y-m-d H:i:s")
        ], [
            "id_user" => $userId
        ]);
    }

    public function updateOnboardTime(int $userId): bool
    {
        return $this->update([
            "onboarded_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ], [
            "id_user" => $userId
        ]);
    }
}
