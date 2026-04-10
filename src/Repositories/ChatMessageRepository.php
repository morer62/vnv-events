<?php

namespace App\Repositories;

use App\Repositories\BaseRepository;
use App\Repositories\Connection;

class ChatMessageRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "chat_messages";
        $this->fields = ["id_thread", "id_sender", "message", "created_at", "is_read"];
        $this->db = new Connection();
    }

    public function getMessagesForThread(int $threadId): array
    {
        $this->db->query("
            SELECT m.*, u.name AS sender_name, u.email AS sender_email
            FROM {$this->table} m
            JOIN users u ON m.id_sender = u.id
            WHERE m.id_thread = :id
            ORDER BY m.created_at ASC
        ");
        $this->db->bind(':id', $threadId);
        return $this->db->fetchAll();
    }


    public function markAsRead(int $threadId, int $userId): void
    {
        $this->db->query("
            UPDATE {$this->table}
            SET is_read = 1
            WHERE id_thread = :thread AND id_sender != :sender
        ");
        $this->db->bind(':thread', $threadId);
        $this->db->bind(':sender', $userId);
        $this->db->execute();
    }

    public function insertMessage(int $threadId, int $senderId, string $message): void
    {
        $this->add([
            "id_thread" => $threadId,
            "id_sender" => $senderId,
            "message" => $message,
            "is_read" => 0
        ]);
    }
}
