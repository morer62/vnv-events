<?php

namespace App\Repositories;

use App\Repositories\BaseRepository;

class UserDeletionRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "user_deletion"; 
        $this->db = new Connection();
    }

    public function requestDeletion(int $userId,$deletion_status): void
    {
        $this->deleteExisting($userId); // Opcional: limpiar solicitudes previas
        $this->add([
            "id_user" => $userId,
            "deletion_request" => date("Y-m-d H:i:s"),
            "deletion_date" => date("Y-m-d H:i:s", strtotime("+30 days")),
            "deletion_status" => $deletion_status
        ]);
    }

    public function getByUser(int $userId): ?object
    {
        $items = $this->getAllBy(["id_user" => $userId]);
        return $items[0] ?? null;
    }

    public function deleteExisting(int $userId): void
    {
        $this->delete(["id_user" => $userId]);
    }
}
