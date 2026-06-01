<?php

namespace App\Repositories;

class EventRequestRepository extends BaseRepository
{
    protected array $fields = [
        'id_owner',
        'id_user',
        'full_name',
        'email',
        'phone',
        'event_address',
        'event_date',
        'event_time',
        'guest_count',
        'selected_services',
        'details',
        'form_source',
        'status',
        'is_archived',
        'archived_at',
        'created_at',
        'updated_at',
    ];

    public function __construct()
    {
        $this->table = 'event_requests';
        $this->db = new Connection();
    }

    public function createFromPublicForm(array $data): int
    {
        $allowed = array_flip($this->fields);
        $insert = array_intersect_key($data, $allowed);
        unset($insert['id'], $insert['created_at'], $insert['updated_at'], $insert['archived_at']);

        $keys = array_keys($insert);
        $columns = implode(', ', array_map(fn ($key) => "`{$key}`", $keys));
        $placeholders = implode(', ', array_map(fn ($key) => ":{$key}", $keys));

        $this->db->query("INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})");
        foreach ($insert as $key => $value) {
            $this->db->bind(":{$key}", $value);
        }
        $this->db->execute();

        return (int)$this->db->lastId();
    }

    public function latestForOwner(int $ownerId, int $limit = 6, bool $archived = false): array
    {
        try {
            $this->db->query("
                SELECT *
                FROM `{$this->table}`
                WHERE `id_owner` = :id_owner
                  AND `is_archived` = :is_archived
                ORDER BY `created_at` DESC
                LIMIT :limit
            ");
            $this->db->bind(':id_owner', $ownerId);
            $this->db->bind(':is_archived', $archived ? 1 : 0);
            $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

            return $this->db->fetchAll();
        } catch (\Throwable $e) {
            error_log('EventRequestRepository::latestForOwner error: ' . $e->getMessage());
            return [];
        }
    }

    public function countForOwner(int $ownerId, bool $archived = false): int
    {
        try {
            $this->db->query("
                SELECT COUNT(*) AS total
                FROM `{$this->table}`
                WHERE `id_owner` = :id_owner
                  AND `is_archived` = :is_archived
            ");
            $this->db->bind(':id_owner', $ownerId);
            $this->db->bind(':is_archived', $archived ? 1 : 0);
            $row = $this->db->fetchOne();

            return (int)($row->total ?? 0);
        } catch (\Throwable $e) {
            error_log('EventRequestRepository::countForOwner error: ' . $e->getMessage());
            return 0;
        }
    }

    public function archiveForOwner(int $id, int $ownerId): bool
    {
        $this->db->query("
            UPDATE `{$this->table}`
            SET `is_archived` = 1,
                `archived_at` = NOW(),
                `updated_at` = NOW()
            WHERE `id` = :id
              AND `id_owner` = :id_owner
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':id_owner', $ownerId);
        $this->db->execute();

        return $this->db->rowCount() > 0;
    }
}
