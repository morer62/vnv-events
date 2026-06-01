<?php

namespace App\Repositories;

class SeoFilesLogRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "seo_files_logs";
        $this->db = new Connection();
    }

    public function record(array $data): bool
    {
        try {
            return $this->add([
                'file_type' => $data['file_type'] ?? 'unknown',
                'generated_by' => $data['generated_by'] ?? null,
                'status' => $data['status'] ?? 'success',
                'message' => $data['message'] ?? null,
                'items_count' => $data['items_count'] ?? 0,
                'file_path' => $data['file_path'] ?? null,
                'public_url' => $data['public_url'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('SEO files log failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getLatestByType(string $fileType): ?object
    {
        try {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE file_type = :file_type
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $this->db->bind(':file_type', $fileType);

            $result = $this->db->fetchOne();
            return $result ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getLatest(): ?object
    {
        try {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");

            $result = $this->db->fetchOne();
            return $result ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
