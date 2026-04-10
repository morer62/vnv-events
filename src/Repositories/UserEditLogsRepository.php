<?php

namespace App\Repositories;

use App\Repositories\Connection;

class UserEditLogsRepository
{
    protected string $table = 'user_edit_logs';
    protected $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function addEditLog(int $userId, int $editedBy, string $changes): bool
    {
        try {
            $this->db->query("
                INSERT INTO {$this->table} (user_id, edited_by, changes, created_at)
                VALUES (:user_id, :edited_by, :changes, NOW())
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":edited_by", $editedBy);
            $this->db->bind(":changes", $changes);
            
            return (bool)$this->db->execute();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getEditLogsForUser(int $userId): array
    {
        $this->db->query("
            SELECT uel.*, u.name as editor_name, u.lastname as editor_lastname
            FROM {$this->table} uel
            JOIN users u ON uel.edited_by = u.id
            WHERE uel.user_id = :user_id
            ORDER BY uel.created_at DESC
        ");
        $this->db->bind(":user_id", $userId);
        
        return $this->db->fetchAll();
    }

    public function getEditLogsByEditor(int $editedBy): array
    {
        $this->db->query("
            SELECT uel.*, u.name as user_name, u.lastname as user_lastname
            FROM {$this->table} uel
            JOIN users u ON uel.user_id = u.id
            WHERE uel.edited_by = :edited_by
            ORDER BY uel.created_at DESC
        ");
        $this->db->bind(":edited_by", $editedBy);
        
        return $this->db->fetchAll();
    }

    public function logEdit(int $userId, int $editedBy, array $changes): void
    {
        $this->db->query("
            INSERT INTO {$this->table} (user_id, edited_by, changes)
            VALUES (:user_id, :edited_by, :changes)
        ");
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":edited_by", $editedBy);
        $this->db->bind(":changes", json_encode($changes));
        $this->db->execute();
    }

    public function getEditsByUser(int $userId): array
    {
        $this->db->query("
            SELECT 
                uel.*,
                u_editor.name as editor_name,
                u_editor.lastname as editor_lastname,
                u_editor.email as editor_email,
                ip.company_name as editor_company
            FROM {$this->table} uel
            LEFT JOIN users u_editor ON uel.edited_by = u_editor.id
            LEFT JOIN institution_profile ip ON u_editor.id_owner = ip.id_owner
            WHERE uel.user_id = :user_id
            ORDER BY uel.created_at DESC
        ");
        $this->db->bind(":user_id", $userId);
        $results = $this->db->fetchAll();
        
        foreach ($results as &$result) {
            if ($result->changes) {
                $result->changes = json_decode($result->changes, true);
            }
        }
        
        return $results;
    }

    public function getEditById(int $id): ?object
    {
        $this->db->query("
            SELECT 
                uel.*,
                u_editor.name as editor_name,
                u_editor.lastname as editor_lastname,
                u_editor.email as editor_email,
                ip.company_name as editor_company
            FROM {$this->table} uel
            LEFT JOIN users u_editor ON uel.edited_by = u_editor.id
            LEFT JOIN institution_profile ip ON u_editor.id_owner = ip.id_owner
            WHERE uel.id = :id
        ");
        $this->db->bind(":id", $id);
        $result = $this->db->fetchOne();
        
        if ($result && $result->changes) {
            $result->changes = json_decode($result->changes, true);
        }
        
        return $result ?: null;
    }

    public function getPendingEditsForOwner(int $ownerId): array
    {
        $this->db->query("
            SELECT 
                uel.*,
                u_target.name as user_name,
                u_target.lastname as user_lastname,
                u_target.email as user_email,
                u_editor.name as editor_name,
                u_editor.lastname as editor_lastname,
                u_editor.email as editor_email,
                ip_editor.company_name as editor_company
            FROM {$this->table} uel
            JOIN users u_target ON uel.user_id = u_target.id
            LEFT JOIN users u_editor ON uel.edited_by = u_editor.id
            LEFT JOIN institution_profile ip_editor ON u_editor.id_owner = ip_editor.id_owner
            WHERE u_target.id_owner = :owner_id
            AND u_editor.id_owner != :owner_id
            ORDER BY uel.created_at DESC
        ");
        $this->db->bind(":owner_id", $ownerId);
        $results = $this->db->fetchAll();
        
        foreach ($results as &$result) {
            if ($result->changes) {
                $result->changes = json_decode($result->changes, true);
            }
        }
        
        return $results;
    }

    public function delete(array $conditions): bool
    {
        $where = [];
        foreach ($conditions as $key => $value) {
            $where[] = "$key = :$key";
        }
        $whereClause = implode(' AND ', $where);
        
        $this->db->query("DELETE FROM {$this->table} WHERE $whereClause");
        
        foreach ($conditions as $key => $value) {
            $this->db->bind(":$key", $value);
        }
        
        return (bool)$this->db->execute();
    }
}

