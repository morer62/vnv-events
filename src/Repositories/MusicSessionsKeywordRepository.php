<?php

namespace App\Repositories;

class MusicSessionsKeywordRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "music_sessions_keywords";
        $this->db = new Connection();
    }

    public function getAllByUser(int $userId): array
    {
        return $this->getAllBy(["id_user" => $userId]);
    }

    public function getOrCreate(string $keyword, int $userId): int
    {
        $keyword = trim($keyword);
        if (empty($keyword)) {
            return 0;
        }

        $existing = $this->getOne(["id_user" => $userId, "keyword" => $keyword]);
        if ($existing) {
            return $existing->id;
        }

        if ($this->add([
            "keyword" => $keyword,
            "id_user" => $userId
        ])) {
            return (int)$this->db->lastId();
        }

        return 0;
    }

    public function searchByUser(string $search, int $userId, int $limit = 10): array
    {
        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE id_user = :id_user 
            AND keyword LIKE :search
            ORDER BY keyword ASC
            LIMIT :limit
        ");
        $this->db->bind(":id_user", $userId);
        $this->db->bind(":search", "%{$search}%");
        $this->db->bind(":limit", $limit);
        return $this->db->fetchAll();
    }
}

