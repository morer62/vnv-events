<?php

namespace App\Repositories;

use Throwable;

class AiContentDraftsRepository extends BaseRepository
{
    protected string $table = 'ai_content_drafts';

    protected array $fields = [
        'id',
        'id_owner',
        'id_user_business',
        'site_key',
        'brand_name',
        'content_type',
        'status',
        'language',
        'title',
        'slug',
        'topic',
        'service_name',
        'city',
        'state',
        'excerpt',
        'body_html',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'schema_json',
        'faq_json',
        'thumbnail_prompt',
        'featured_image_url',
        'source_notes_json',
        'internal_links_json',
        'ai_model',
        'ai_prompt_hash',
        'review_feedback',
        'voice_note_path',
        'published_entity_type',
        'published_entity_id',
        'published_at',
        'created_by',
        'reviewed_by',
        'approved_by',
        'created_at',
        'updated_at',
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

    public function addDraft(array $data): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $ok = $this->add($data);
        return $ok ? $this->getLastId() : 0;
    }

    public function find(int $id): ?object
    {
        if (!$this->tableExists()) {
            return null;
        }

        $this->db->query("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $id);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function latestForPanel(?string $siteKey = null, int $limit = 80): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $siteKey = $this->normalizeSiteKey($siteKey);

        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE site_key = :site_key
            ORDER BY
                CASE status
                    WHEN 'NEEDS_REVIEW' THEN 1
                    WHEN 'REVISION_REQUESTED' THEN 2
                    WHEN 'APPROVED' THEN 3
                    WHEN 'DRAFT' THEN 4
                    WHEN 'IDEA' THEN 5
                    ELSE 9
                END,
                updated_at DESC,
                id DESC
            LIMIT :limit
        ");
        $this->db->bind(':site_key', $siteKey);
        $this->db->bind(':limit', $limit);

        return $this->db->fetchAll() ?: [];
    }

    public function pendingCount(?string $siteKey = null): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $siteKey = $this->normalizeSiteKey($siteKey);

        $this->db->query("
            SELECT COUNT(*) AS total
            FROM {$this->table}
            WHERE site_key = :site_key
              AND status IN ('IDEA', 'DRAFT', 'NEEDS_REVIEW', 'REVISION_REQUESTED')
        ");
        $this->db->bind(':site_key', $siteKey);
        $row = $this->db->fetchOne();

        return $row ? (int)$row->total : 0;
    }

    public function slugExists(string $slug, string $contentType, ?string $siteKey = null, int $excludeId = 0): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $siteKey = $this->normalizeSiteKey($siteKey);

        $sql = "
            SELECT COUNT(*) AS total
            FROM {$this->table}
            WHERE slug = :slug
              AND content_type = :content_type
              AND site_key = :site_key
        ";

        if ($excludeId > 0) {
            $sql .= " AND id != :exclude_id";
        }

        $this->db->query($sql);
        $this->db->bind(':slug', $slug);
        $this->db->bind(':content_type', $contentType);
        $this->db->bind(':site_key', $siteKey);
        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $row = $this->db->fetchOne();
        return $row && (int)$row->total > 0;
    }

    public function locationTopicExists(string $service, string $city, string $contentType, ?string $siteKey = null): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $siteKey = $this->normalizeSiteKey($siteKey);

        $this->db->query("
            SELECT COUNT(*) AS total
            FROM {$this->table}
            WHERE site_key = :site_key
              AND content_type = :content_type
              AND LOWER(service_name) = LOWER(:service_name)
              AND LOWER(city) = LOWER(:city)
              AND status NOT IN ('REJECTED', 'ARCHIVED')
        ");
        $this->db->bind(':site_key', $siteKey);
        $this->db->bind(':content_type', $contentType);
        $this->db->bind(':service_name', $service);
        $this->db->bind(':city', $city);

        $row = $this->db->fetchOne();
        return $row && (int)$row->total > 0;
    }

    public function updateStatus(int $id, string $status, ?int $userId = null, ?string $feedback = null): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $fields = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($feedback !== null) {
            $fields['review_feedback'] = $feedback;
        }

        if (in_array($status, ['APPROVED', 'PUBLISHED'], true) && $userId) {
            $fields['approved_by'] = $userId;
        }

        if (in_array($status, ['APPROVED', 'REJECTED', 'REVISION_REQUESTED', 'ARCHIVED'], true) && $userId) {
            $fields['reviewed_by'] = $userId;
        }

        return $this->update($fields, ['id' => $id]);
    }

    public function markPublished(int $id, string $entityType, int $entityId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->update([
            'status' => 'PUBLISHED',
            'published_entity_type' => $entityType,
            'published_entity_id' => $entityId,
            'published_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function setVoiceNote(int $id, string $path): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->update([
            'voice_note_path' => $path,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function replaceDraftContent(int $id, array $data): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $allowed = array_intersect_key($data, array_flip([
            'status',
            'title',
            'slug',
            'topic',
            'service_name',
            'city',
            'state',
            'excerpt',
            'body_html',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'schema_json',
            'faq_json',
            'thumbnail_prompt',
            'featured_image_url',
            'source_notes_json',
            'internal_links_json',
            'ai_model',
            'ai_prompt_hash',
            'review_feedback',
        ]));

        $allowed['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($allowed, ['id' => $id]);
    }

    private function normalizeSiteKey(?string $siteKey): string
    {
        $siteKey = trim((string)($siteKey ?? ''));
        return $siteKey !== '' ? strtolower($siteKey) : strtolower((string)($_ENV['AI_CONTENT_SITE_KEY'] ?? 'vnv_events'));
    }
}
