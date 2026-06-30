<?php

namespace App\Repositories;

use App\Utils\SiteContext;

class SeoIndexingQueueRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = 'seo_indexing_queue';
        $this->db = new Connection();
    }

    public function syncPublishedUrls(array $entries, ?string $siteKey = null): int
    {
        $siteKey = $siteKey ?: SiteContext::siteKey();
        $existing = $this->existingUrls($siteKey);
        $inserted = 0;

        foreach ($entries as $entry) {
            $url = trim((string)($entry['loc'] ?? ''));
            if ($url === '' || isset($existing[$url])) {
                continue;
            }

            $route = $this->routeFromUrl($url);
            $this->db->query("
                INSERT INTO {$this->table}
                    (site_key, url, route, title, source_type, source_id, status, created_at, updated_at)
                VALUES
                    (:site_key, :url, :route, :title, :source_type, :source_id, 'pending', NOW(), NOW())
            ");
            $this->db->bind(':site_key', $siteKey);
            $this->db->bind(':url', $url);
            $this->db->bind(':route', $route);
            $this->db->bind(':title', $this->cleanTitle((string)($entry['title'] ?? $route)));
            $this->db->bind(':source_type', $this->cleanSourceType((string)($entry['type'] ?? 'public_url')));
            $this->db->bind(':source_id', isset($entry['source_id']) ? (int)$entry['source_id'] : null);
            $this->db->execute();

            $existing[$url] = true;
            $inserted++;
        }

        return $inserted;
    }

    public function listByStatus(string $status, ?string $siteKey = null): array
    {
        $status = $this->normalizeStatus($status);
        $siteKey = $siteKey ?: SiteContext::siteKey();

        $this->db->query("
            SELECT q.*, u.name AS indexed_by_name, u.lastname AS indexed_by_lastname
            FROM {$this->table} q
            LEFT JOIN users u ON u.id = q.indexed_by
            WHERE q.site_key = :site_key
              AND q.status = :status
            ORDER BY
              CASE WHEN q.status = 'pending' THEN q.created_at ELSE q.indexed_at END DESC,
              q.id DESC
        ");
        $this->db->bind(':site_key', $siteKey);
        $this->db->bind(':status', $status);

        return $this->db->fetchAll() ?: [];
    }

    public function countByStatus(?string $siteKey = null): array
    {
        $siteKey = $siteKey ?: SiteContext::siteKey();
        $this->db->query("
            SELECT status, COUNT(*) AS total
            FROM {$this->table}
            WHERE site_key = :site_key
            GROUP BY status
        ");
        $this->db->bind(':site_key', $siteKey);

        $counts = ['pending' => 0, 'indexed' => 0];
        foreach ($this->db->fetchAll() ?: [] as $row) {
            $status = (string)($row->status ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)($row->total ?? 0);
            }
        }

        return $counts;
    }

    public function markIndexed(int $id, int $userId, ?string $siteKey = null): bool
    {
        $siteKey = $siteKey ?: SiteContext::siteKey();
        $this->db->query("
            UPDATE {$this->table}
            SET status = 'indexed',
                indexed_by = :indexed_by,
                indexed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
              AND site_key = :site_key
        ");
        $this->db->bind(':indexed_by', $userId);
        $this->db->bind(':id', $id);
        $this->db->bind(':site_key', $siteKey);
        $this->db->execute();

        return $this->db->rowCount() > 0;
    }

    public function markPending(int $id, ?string $siteKey = null): bool
    {
        $siteKey = $siteKey ?: SiteContext::siteKey();
        $this->db->query("
            UPDATE {$this->table}
            SET status = 'pending',
                indexed_by = NULL,
                indexed_at = NULL,
                updated_at = NOW()
            WHERE id = :id
              AND site_key = :site_key
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':site_key', $siteKey);
        $this->db->execute();

        return $this->db->rowCount() > 0;
    }

    private function existingUrls(string $siteKey): array
    {
        $this->db->query("SELECT url FROM {$this->table} WHERE site_key = :site_key");
        $this->db->bind(':site_key', $siteKey);

        $existing = [];
        foreach ($this->db->fetchAll() ?: [] as $row) {
            $url = (string)($row->url ?? '');
            if ($url !== '') {
                $existing[$url] = true;
            }
        }

        return $existing;
    }

    private function normalizeStatus(string $status): string
    {
        return $status === 'indexed' ? 'indexed' : 'pending';
    }

    private function routeFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : $path . '/';
    }

    private function cleanTitle(string $title): string
    {
        $title = trim(strip_tags($title));
        return mb_substr($title !== '' ? $title : 'Public URL', 0, 255);
    }

    private function cleanSourceType(string $sourceType): string
    {
        $sourceType = strtolower(trim($sourceType));
        $sourceType = preg_replace('/[^a-z0-9_\-]/', '_', $sourceType) ?: 'public_url';

        return mb_substr($sourceType, 0, 80);
    }
}
