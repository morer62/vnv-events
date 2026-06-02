<?php

namespace App\Repositories;

use Throwable;

class AiContentReviewsRepository extends BaseRepository
{
    protected string $table = 'ai_content_reviews';

    protected array $fields = [
        'id',
        'id_draft',
        'id_user',
        'action',
        'feedback',
        'voice_note_path',
        'created_at',
    ];

    private ?bool $tableAvailable = null;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?: new Connection();
    }

    public function tableExists(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            $this->db->query("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$this->table}'
                LIMIT 1
            ");
            $this->tableAvailable = (bool)$this->db->fetchOne();
        } catch (Throwable $e) {
            $this->tableAvailable = false;
        }

        return $this->tableAvailable;
    }

    public function log(int $draftId, int $userId, string $action, ?string $feedback = null, ?string $voiceNotePath = null): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->add([
            'id_draft' => $draftId,
            'id_user' => $userId,
            'action' => $action,
            'feedback' => $feedback,
            'voice_note_path' => $voiceNotePath,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function forDraft(int $draftId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $this->db->query("
            SELECT r.*, CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.last_name, '')) AS user_name
            FROM {$this->table} r
            LEFT JOIN users u ON u.id = r.id_user
            WHERE r.id_draft = :id_draft
            ORDER BY r.id DESC
        ");
        $this->db->bind(':id_draft', $draftId);

        return $this->db->fetchAll() ?: [];
    }
}
