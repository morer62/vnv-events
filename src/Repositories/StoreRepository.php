<?php

namespace App\Repositories;

use App\Services\LoginService;

abstract class StoreRepository extends BaseRepository
{
    public function getScopedByOwner(int $ownerId, int $limit = 500, array $columns = []): array
    {
        $columnsSql = $columns ? implode(', ', array_map(fn($column) => "`{$column}`", $columns)) : '*';
        $limitSql = $limit > 0 ? " LIMIT {$limit}" : '';

        $this->db->query("SELECT {$columnsSql} FROM {$this->table} WHERE id_owner = :owner ORDER BY id DESC{$limitSql}");
        $this->db->bind(':owner', $ownerId);

        return $this->db->fetchAll();
    }

    public function getOneByOwner(array $criteria, int $ownerId, array $columns = []): ?object
    {
        $columnsSql = $columns ? implode(', ', array_map(fn($column) => "`{$column}`", $columns)) : '*';
        $criteria['id_owner'] = $ownerId;
        $keys = array_keys($criteria);
        $where = implode(' AND ', array_map(fn($key) => "`{$key}` = :{$key}", $keys));

        $this->db->query("SELECT {$columnsSql} FROM {$this->table} WHERE {$where} LIMIT 1");
        foreach ($criteria as $key => $value) {
            $this->db->bind(":{$key}", $value);
        }

        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    public function updateByOwner(array $data, array $criteria, int $ownerId): bool
    {
        $criteria['id_owner'] = $ownerId;
        return $this->update($data, $criteria);
    }

    protected function ownerId(?int $ownerId = null): int
    {
        if ($ownerId && $ownerId > 0) {
            return $ownerId;
        }

        try {
            $session = LoginService::getSession();
            if ($session) {
                return (int)$session->getOwner();
            }
        } catch (\Throwable $e) {
        }

        return max(0, (int)($_ENV['STORE_OWNER_ID'] ?? $_ENV['DEFAULT_OWNER_ID'] ?? 0));
    }

    protected function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
        return trim($slug, '-') ?: 'item';
    }

    protected function uniqueSlug(string $table, string $value, ?int $excludeId = null, ?int $ownerId = null, array $extra = []): string
    {
        $base = $this->slugify($value);
        $candidate = $base;
        $suffix = 2;

        do {
            $sql = "SELECT id FROM {$table} WHERE slug = :slug";
            $params = [':slug' => $candidate];
            $owner = $this->ownerId($ownerId);
            if ($owner > 0) {
                $sql .= ' AND id_owner = :owner';
                $params[':owner'] = $owner;
            }
            foreach ($extra as $field => $fieldValue) {
                $sql .= " AND {$field} = :{$field}";
                $params[":{$field}"] = $fieldValue;
            }
            if ($excludeId) {
                $sql .= ' AND id <> :exclude';
                $params[':exclude'] = $excludeId;
            }
            $sql .= ' LIMIT 1';
            $this->db->query($sql);
            foreach ($params as $param => $paramValue) {
                $this->db->bind($param, $paramValue);
            }
            $exists = (bool)$this->db->fetchOne();
            if ($exists) {
                $candidate = $base . '-' . $suffix++;
            }
        } while ($exists);

        return $candidate;
    }
}
