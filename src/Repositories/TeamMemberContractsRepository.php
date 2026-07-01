<?php

namespace App\Repositories;

class TeamMemberContractsRepository
{
    private string $table = 'team_member_contracts';
    private Connection $db;
    private static ?bool $tableExists = null;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function create(array $data): int
    {
        if (!$this->hasStorage()) {
            throw new \RuntimeException('team_member_contracts table is not available.');
        }

        $fields = array_keys($data);
        $columns = '`' . implode('`, `', $fields) . '`';
        $placeholders = ':' . implode(', :', $fields);

        $this->db->query("INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})");
        foreach ($data as $field => $value) {
            $this->db->bind(":{$field}", $value);
        }
        $this->db->execute();

        return (int)$this->db->lastId();
    }

    public function updateById(int $id, array $data): bool
    {
        if (!$data || !$this->hasStorage()) {
            return false;
        }

        $parts = [];
        foreach (array_keys($data) as $field) {
            $parts[] = "`{$field}` = :{$field}";
        }

        $this->db->query("UPDATE `{$this->table}` SET " . implode(', ', $parts) . " WHERE id = :id");
        foreach ($data as $field => $value) {
            $this->db->bind(":{$field}", $value);
        }
        $this->db->bind(':id', $id);
        $this->db->execute();

        return true;
    }

    public function getById(int $id): ?object
    {
        if (!$this->hasStorage()) {
            return null;
        }

        $this->db->query("SELECT * FROM `{$this->table}` WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $id);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function getByIdAndOwner(int $id, int $ownerId): ?object
    {
        if (!$this->hasStorage()) {
            return null;
        }

        $this->db->query("SELECT * FROM `{$this->table}` WHERE id = :id AND id_owner = :owner LIMIT 1");
        $this->db->bind(':id', $id);
        $this->db->bind(':owner', $ownerId);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function getByIdAndMember(int $id, int $teamMemberId): ?object
    {
        if (!$this->hasStorage()) {
            return null;
        }

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE id = :id
              AND team_member_id = :team_member_id
            LIMIT 1
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':team_member_id', $teamMemberId);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function getLatestForMember(int $teamMemberId, int $ownerId): ?object
    {
        if (!$this->hasStorage()) {
            return null;
        }

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE team_member_id = :team_member_id
              AND id_owner = :owner
            ORDER BY COALESCE(validated_at, signed_at, uploaded_at, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $this->db->bind(':team_member_id', $teamMemberId);
        $this->db->bind(':owner', $ownerId);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function getLatestActiveForMember(int $teamMemberId): ?object
    {
        if (!$this->hasStorage()) {
            return null;
        }

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE team_member_id = :team_member_id
              AND status IN ('SIGNED', 'VALIDATED', 'MANUALLY_UPLOADED')
            ORDER BY COALESCE(validated_at, signed_at, uploaded_at, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $this->db->bind(':team_member_id', $teamMemberId);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function getAllForMember(int $teamMemberId, int $ownerId): array
    {
        if (!$this->hasStorage()) {
            return [];
        }

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE team_member_id = :team_member_id
              AND id_owner = :owner
            ORDER BY created_at DESC, id DESC
        ");
        $this->db->bind(':team_member_id', $teamMemberId);
        $this->db->bind(':owner', $ownerId);

        return $this->db->fetchAll();
    }

    public function getLatestByMembers(array $memberIds, int $ownerId): array
    {
        if (!$this->hasStorage()) {
            return [];
        }

        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if (!$memberIds) {
            return [];
        }

        $placeholders = [];
        foreach ($memberIds as $index => $id) {
            $placeholders[] = ":member_{$index}";
        }

        $this->db->query("
            SELECT tmc.*
            FROM `{$this->table}` tmc
            INNER JOIN (
                SELECT team_member_id, MAX(id) latest_id
                FROM `{$this->table}`
                WHERE id_owner = :owner
                  AND team_member_id IN (" . implode(', ', $placeholders) . ")
                GROUP BY team_member_id
            ) latest ON latest.latest_id = tmc.id
        ");
        $this->db->bind(':owner', $ownerId);
        foreach ($memberIds as $index => $id) {
            $this->db->bind(":member_{$index}", $id);
        }

        $rows = $this->db->fetchAll();
        $byMember = [];
        foreach ($rows as $row) {
            $byMember[(int)$row->team_member_id] = $row;
        }

        return $byMember;
    }

    public function hasStorage(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            $this->db->query("SHOW TABLES LIKE 'team_member_contracts'");
            self::$tableExists = (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }
}
