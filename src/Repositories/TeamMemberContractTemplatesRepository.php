<?php

namespace App\Repositories;

class TeamMemberContractTemplatesRepository
{
    private string $table = 'team_member_contract_templates';
    public Connection $db;
    private static ?bool $tableExists = null;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function hasStorage(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            $this->db->query("SHOW TABLES LIKE '{$this->table}'");
            self::$tableExists = (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    public function getAllByOwner(int $ownerId): array
    {
        if (!$this->hasStorage()) {
            return [];
        }

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE id_owner = :owner
              AND status = 'ACTIVE'
            ORDER BY updated_at DESC, id DESC
        ");
        $this->db->bind(':owner', $ownerId);

        return $this->db->fetchAll();
    }

    public function getOneByIdAndOwner(int $id, int $ownerId): ?object
    {
        if (!$this->hasStorage()) {
            return null;
        }

        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE id = :id
              AND id_owner = :owner
              AND status = 'ACTIVE'
            LIMIT 1
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':owner', $ownerId);
        $row = $this->db->fetchOne();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        if (!$this->hasStorage()) {
            return 0;
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

    public function archiveByIdAndOwner(int $id, int $ownerId): bool
    {
        if (!$this->hasStorage()) {
            return false;
        }

        $this->db->query("
            UPDATE `{$this->table}`
            SET status = 'ARCHIVED', updated_at = NOW()
            WHERE id = :id
              AND id_owner = :owner
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':owner', $ownerId);
        $this->db->execute();

        return true;
    }
}
