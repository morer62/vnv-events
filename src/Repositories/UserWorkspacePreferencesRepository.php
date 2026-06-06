<?php

namespace App\Repositories;

class UserWorkspacePreferencesRepository
{
    private Connection $db;
    private string $table = 'user_workspace_preferences';

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getByUserId(int $userId): ?object
    {
        if (!$this->tableExists()) {
            return null;
        }

        try {
            $this->db->query("SELECT * FROM {$this->table} WHERE user_id = :user_id LIMIT 1");
            $this->db->bind(':user_id', $userId);

            return $this->db->fetchOne() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function saveSelection(
        int $userId,
        string $workspaceType,
        ?int $selectedOwnerId = null,
        ?int $selectedInstitutionId = null,
        ?string $selectedRole = null
    ): bool {
        if (!$this->tableExists()) {
            return false;
        }

        $workspaceType = strtoupper($workspaceType);
        if (!in_array($workspaceType, ['BUSINESS_OWNER', 'TEAM_MEMBER', 'CLIENT'], true)) {
            return false;
        }

        try {
            $this->db->query("INSERT INTO {$this->table}
                (user_id, workspace_type, selected_owner_id, selected_institution_id, selected_role, created_at, updated_at)
                VALUES (:user_id, :workspace_type, :selected_owner_id, :selected_institution_id, :selected_role, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    workspace_type = VALUES(workspace_type),
                    selected_owner_id = VALUES(selected_owner_id),
                    selected_institution_id = VALUES(selected_institution_id),
                    selected_role = VALUES(selected_role),
                    updated_at = NOW()");
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':workspace_type', $workspaceType);
            $this->db->bind(':selected_owner_id', $selectedOwnerId);
            $this->db->bind(':selected_institution_id', $selectedInstitutionId);
            $this->db->bind(':selected_role', $selectedRole);
            $this->db->execute();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function tableExists(): bool
    {
        try {
            $this->db->query("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                LIMIT 1
            ");
            $this->db->bind(':table_name', $this->table);

            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
