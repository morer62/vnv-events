<?php

namespace App\Repositories;

class MusicSessionsCategoryRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "music_sessions_categories";
        $this->db = new Connection();
    }

    public function getAllByUser(int $userId): array
    {
        return $this->getAllBy(["id_user" => $userId]);
    }
}

