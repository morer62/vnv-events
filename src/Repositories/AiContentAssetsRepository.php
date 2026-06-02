<?php

namespace App\Repositories;

use Throwable;

class AiContentAssetsRepository extends BaseRepository
{
    protected string $table = 'ai_content_assets';

    protected array $fields = [
        'id',
        'id_draft',
        'asset_type',
        'file_url',
        'original_name',
        'mime_type',
        'notes',
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

    public function addAsset(int $draftId, string $type, string $url, ?string $originalName = null, ?string $mimeType = null, ?string $notes = null): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->add([
            'id_draft' => $draftId,
            'asset_type' => $type,
            'file_url' => $url,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'notes' => $notes,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
