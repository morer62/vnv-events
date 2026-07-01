<?php

namespace App\Repositories;

use Throwable;

class MobileAppBroadcastsRepository extends BaseRepository
{
    protected string $table = 'mobile_app_broadcasts';

    public function __construct()
    {
        $this->db = new Connection();
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        try {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS mobile_app_broadcasts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_user_sender INT NULL,
                    title VARCHAR(160) NOT NULL,
                    body TEXT NOT NULL,
                    link VARCHAR(500) NULL,
                    target_scope VARCHAR(60) NOT NULL DEFAULT 'all_mobile_app_users',
                    recipient_count INT NOT NULL DEFAULT 0,
                    push_sent_count INT NOT NULL DEFAULT 0,
                    push_failed_count INT NOT NULL DEFAULT 0,
                    notification_count INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->execute();
        } catch (Throwable $e) {
            error_log('[MobileAppBroadcasts] ensureTable failed: ' . $e->getMessage());
        }
    }

    public function createBroadcast(?int $senderId, string $title, string $body, ?string $link): int
    {
        $this->add([
            'id_user_sender' => $senderId,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);

        return (int)$this->db->lastId();
    }

    public function updateStats(int $id, int $recipients, int $pushSent, int $pushFailed, int $notifications): bool
    {
        return $this->update([
            'recipient_count' => $recipients,
            'push_sent_count' => $pushSent,
            'push_failed_count' => $pushFailed,
            'notification_count' => $notifications,
        ], ['id' => $id]);
    }

    public function getRecent(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $this->db->query("SELECT * FROM {$this->table} ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        return $this->db->fetchAll();
    }
}
