<?php

namespace App\Repositories;

use App\Repositories\BaseRepository;
use App\Repositories\Connection;
use App\Services\LoginService;

class ChatThreadRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "chat_threads";
        $this->fields = ["id_user_1", "id_user_2", "created_at"];
        $this->db = new Connection();
    }

    public function findOrCreateThread(int $user1, int $user2): ?object
    {
        $ids = [$user1, $user2];
        sort($ids);

        $existing = $this->getOne([
            "id_user_1" => $ids[0],
            "id_user_2" => $ids[1]
        ]);

        if ($existing) return $existing;

        $this->add([
            "id_user_1" => $ids[0],
            "id_user_2" => $ids[1]
        ]);

        return $this->getOne([
            "id_user_1" => $ids[0],
            "id_user_2" => $ids[1]
        ]);
    }

    public function getAllForUser(int $userId): array
    {
        $this->db->query("
            SELECT 
                t.*, 
                u1.name as name1, u1.email as email1, 
                u2.name as name2, u2.email as email2,
                (SELECT message FROM chat_messages WHERE id_thread = t.id ORDER BY id DESC LIMIT 1) AS last_message,
                (SELECT is_read FROM chat_messages WHERE id_thread = t.id ORDER BY id DESC LIMIT 1) AS last_read,
                (SELECT COUNT(*) FROM chat_messages WHERE id_thread = t.id AND is_read = 0 AND id_sender != :uid) AS unread_count
            FROM {$this->table} t
            JOIN users u1 ON t.id_user_1 = u1.id
            JOIN users u2 ON t.id_user_2 = u2.id
            WHERE t.id_user_1 = :uid OR t.id_user_2 = :uid
            ORDER BY (
                SELECT MAX(created_at) FROM chat_messages WHERE id_thread = t.id
            ) DESC
        ");
        $this->db->bind(':uid', $userId);
        return $this->db->fetchAll();
    }

    public function getUnreadSummariesForUser(int $userId): array
    {
        $this->db->query("
            SELECT
                t.id,
                CASE WHEN t.id_user_1 = :uid THEN t.id_user_2 ELSE t.id_user_1 END AS partner_id,
                CASE WHEN t.id_user_1 = :uid THEN u2.name ELSE u1.name END AS partner_name,
                CASE WHEN t.id_user_1 = :uid THEN u2.email ELSE u1.email END AS partner_email,
                COUNT(m.id) AS unread_count,
                MAX(m.created_at) AS last_unread_at,
                (
                    SELECT cm.message
                    FROM chat_messages cm
                    WHERE cm.id_thread = t.id
                      AND cm.is_read = 0
                      AND cm.id_sender != :uid
                    ORDER BY cm.created_at DESC, cm.id DESC
                    LIMIT 1
                ) AS last_unread_message
            FROM {$this->table} t
            JOIN users u1 ON t.id_user_1 = u1.id
            JOIN users u2 ON t.id_user_2 = u2.id
            JOIN chat_messages m ON m.id_thread = t.id
                AND m.is_read = 0
                AND m.id_sender != :uid
            WHERE t.id_user_1 = :uid OR t.id_user_2 = :uid
            GROUP BY t.id, partner_id, partner_name, partner_email
            ORDER BY last_unread_at DESC
        ");
        $this->db->bind(':uid', $userId);
        return $this->db->fetchAll();
    }

}
