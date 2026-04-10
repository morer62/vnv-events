<?php

namespace App\Repositories;

class MusicSessionsKeywordsRelationsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "music_sessions_keywords_relations";
        $this->db = new Connection();
    }

    public function getKeywordsBySession(int $sessionId): array
    {
        $this->db->query("
            SELECT 
                msk.id,
                msk.keyword
            FROM {$this->table} mskr
            INNER JOIN music_sessions_keywords msk ON mskr.id_keyword = msk.id
            WHERE mskr.id_session = :id_session
            ORDER BY msk.keyword ASC
        ");
        $this->db->bind(":id_session", $sessionId);
        return $this->db->fetchAll();
    }

    public function setSessionKeywords(int $sessionId, array $keywordIds): bool
    {
        $this->delete(["id_session" => $sessionId]);

        foreach ($keywordIds as $keywordId) {
            if ($keywordId > 0) {
                $this->add([
                    "id_session" => $sessionId,
                    "id_keyword" => $keywordId
                ]);
            }
        }

        return true;
    }

    public function addKeywordToSession(int $sessionId, int $keywordId): bool
    {
        $existing = $this->getOne([
            "id_session" => $sessionId,
            "id_keyword" => $keywordId
        ]);

        if ($existing) {
            return true;
        }

        return $this->add([
            "id_session" => $sessionId,
            "id_keyword" => $keywordId
        ]);
    }

    public function removeKeywordFromSession(int $sessionId, int $keywordId): bool
    {
        return $this->delete([
            "id_session" => $sessionId,
            "id_keyword" => $keywordId
        ]);
    }

    public function getSessionsByKeyword(int $keywordId, int $userId): array
    {
        $this->db->query("
            SELECT 
                ms.*
            FROM {$this->table} mskr
            INNER JOIN music_sessions ms ON mskr.id_session = ms.id
            WHERE mskr.id_keyword = :id_keyword
            AND ms.id_user = :id_user
            AND ms.is_active = 1
            ORDER BY ms.created_at DESC
        ");
        $this->db->bind(":id_keyword", $keywordId);
        $this->db->bind(":id_user", $userId);
        return $this->db->fetchAll();
    }
}

