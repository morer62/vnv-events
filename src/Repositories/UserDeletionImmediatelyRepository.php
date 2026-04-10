<?php

namespace App\Repositories;

use App\Repositories\BaseRepository;

class UserDeletionImmediatelyRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "users";
        $this->db = new Connection();
    }

    public function deactivateAccountImmediately(int $userId): void
    {
        $user = $this->getById($userId);

        if (!$user) {
            throw new \Exception("Usuario no encontrado");
        }

        $this->update([
            "is_active" => 0,
            "api_token" => null
        ], [
            "id" => $userId
        ]);



    }

    public function getById(int $userId): ?object
    {
        $items = $this->getAllBy(["id" => $userId]);
        return $items[0] ?? null;
    }
}