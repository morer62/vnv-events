<?php

namespace App\Repositories;

use App\Repositories\Connection;

class UserDeactivationsRepository
{
    protected string $table = 'user_deactivations';
    protected $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function addDeactivation(int $userId, int $institutionId, int $deactivatedBy, ?string $reason = null): bool
    {
        try {
            $this->db->query("
                INSERT INTO {$this->table} (user_id, institution_id, deactivated_by, reason, created_at)
                VALUES (:user_id, :institution_id, :deactivated_by, :reason, NOW())
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":institution_id", $institutionId);
            $this->db->bind(":deactivated_by", $deactivatedBy);
            $this->db->bind(":reason", $reason);
            
            $this->db->execute();
            return $this->db->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getDeactivationsForUser(int $userId): array
    {
        $this->db->query("
            SELECT ud.*, u.name as deactivator_name, u.lastname as deactivator_lastname,
                   ip.company_name as institution_name
            FROM {$this->table} ud
            JOIN users u ON ud.deactivated_by = u.id
            LEFT JOIN institution_profile ip ON ud.institution_id = ip.id
            WHERE ud.user_id = :user_id
            ORDER BY ud.created_at DESC
        ");
        $this->db->bind(":user_id", $userId);
        
        return $this->db->fetchAll();
    }

    public function getDeactivationsByDeactivator(int $deactivatedBy): array
    {
        $this->db->query("
            SELECT ud.*, u.name as user_name, u.lastname as user_lastname,
                   ip.company_name as institution_name
            FROM {$this->table} ud
            JOIN users u ON ud.user_id = u.id
            LEFT JOIN institution_profile ip ON ud.institution_id = ip.id
            WHERE ud.deactivated_by = :deactivated_by
            ORDER BY ud.created_at DESC
        ");
        $this->db->bind(":deactivated_by", $deactivatedBy);
        
        return $this->db->fetchAll();
    }
}
