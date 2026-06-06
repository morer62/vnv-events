<?php

namespace App\Repositories\Concerns;

use App\Utils\SiteContext;

trait ContentOriginRepositoryTrait
{
    private static array $contentOriginColumnCache = [];

    public function withVnvEventsOrigin(array $data, ?int $userId = null, ?int $ownerId = null): array
    {
        $siteKey = SiteContext::siteKey();
        $origin = 'vnv_events';

        $defaults = [
            'id_owner' => $ownerId,
            'site_key' => $siteKey,
            'content_origin' => $origin,
            'origin_site_key' => $siteKey,
            'source_site_key' => $siteKey,
            'source_platform' => 'vnv_events',
            'created_by' => $userId,
            'updated_by' => $userId,
        ];

        foreach ($defaults as $column => $value) {
            if ($value !== null && $this->hasContentOriginColumn($column)) {
                if ($column === 'updated_by' || !array_key_exists($column, $data) || $data[$column] === null || $data[$column] === '') {
                    $data[$column] = $value;
                }
            }
        }

        if ($this->hasContentOriginColumn('origin_metadata_json') && empty($data['origin_metadata_json'])) {
            $data['origin_metadata_json'] = json_encode([
                'origin' => $origin,
                'site_key' => $siteKey,
                'created_in' => 'vnv_events_cms',
                'author_user_id' => $userId,
                'owner_id' => $ownerId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $data;
    }

    protected function hasContentOriginColumn(string $column): bool
    {
        $table = (string)($this->table ?? '');
        if ($table === '' || !$this->db) {
            return false;
        }

        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$contentOriginColumnCache)) {
            return self::$contentOriginColumnCache[$cacheKey];
        }

        try {
            $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE :column");
            $this->db->bind(':column', $column);
            self::$contentOriginColumnCache[$cacheKey] = (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            self::$contentOriginColumnCache[$cacheKey] = false;
        }

        return self::$contentOriginColumnCache[$cacheKey];
    }
}
