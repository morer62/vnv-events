<?php

namespace App\Repositories;

use App\Repositories\Connection;

class UserInstitutionsRepository
{
    protected string $table = 'user_institutions';
    protected $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function addUserToInstitution(int $userId, int $institutionId, ?int $roleId = null, ?float $hourlyRate = null, ?string $contractDetail = null, ?int $secondaryInstitutionId = null): bool
    {
        try {
            $this->db->query("
                INSERT INTO {$this->table} (user_id, institution_id, secondary_institution_id, role_id, hourly_rate, contract_detail, is_active, created_at, updated_at)
                VALUES (:user_id, :institution_id, :secondary_institution_id, :role_id, :hourly_rate, :contract_detail, 1, NOW(), NOW())
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":institution_id", $institutionId);
            $this->db->bind(":secondary_institution_id", $secondaryInstitutionId);
            $this->db->bind(":role_id", $roleId);
            $this->db->bind(":hourly_rate", $hourlyRate);
            $this->db->bind(":contract_detail", $contractDetail);
            
            $this->db->execute();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function exists(int $userId, int $institutionId): bool
    {
        $sql = "
            SELECT id 
            FROM {$this->table} 
            WHERE user_id = :user_id 
              AND institution_id = :institution_id 
              AND is_active = 1
        ";
        
        $sql .= " LIMIT 1";
        
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":institution_id", $institutionId);
        
        return $this->db->count() > 0;
    }

    public function updateUserInstitution(int $userId, int $institutionId, array $data): bool
    {
        try {
            $setClause = [];
            foreach ($data as $key => $value) {
                $setClause[] = "{$key} = :{$key}";
            }
            
            $this->db->query("
                UPDATE {$this->table} 
                SET " . implode(', ', $setClause) . ", updated_at = NOW()
                WHERE user_id = :user_id 
                  AND institution_id = :institution_id 
                  AND is_active = 1
            ");
            
            foreach ($data as $key => $value) {
                $this->db->bind(":{$key}", $value);
            }
            
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":institution_id", $institutionId);
            
            $this->db->execute();
            return $this->db->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removeUserFromInstitution(int $userId, int $institutionId): bool
    {
        try {
            $this->db->query("
                UPDATE {$this->table} 
                SET is_active = 0, updated_at = NOW()
                WHERE user_id = :user_id 
                  AND (institution_id = :institution_id OR secondary_institution_id = :institution_id)
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":institution_id", $institutionId);
            
            $this->db->execute();
            return $this->db->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteUserInstitutionRelationship(int $userId, int $institutionId): bool
    {
        try {
            $this->db->query("
                DELETE FROM {$this->table}
                WHERE user_id = :user_id
                  AND (institution_id = :institution_id OR secondary_institution_id = :institution_id)
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":institution_id", $institutionId);

            return (bool) $this->db->execute();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function reactivateUserInstitutionForInstitution(int $userId, int $institutionId, array $data = []): bool
    {
        try {
            $setParts = ["is_active = 1", "updated_at = NOW()"];
            $params = [
                ":user_id" => $userId,
                ":institution_id" => $institutionId
            ];

            foreach ($data as $field => $value) {
                $setParts[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }

            $sql = "
                UPDATE {$this->table}
                SET " . implode(', ', $setParts) . "
                WHERE user_id = :user_id
                  AND (institution_id = :institution_id OR secondary_institution_id = :institution_id)
                  AND is_active = 0
                LIMIT 1
            ";

            $this->db->query($sql);

            foreach ($params as $param => $value) {
                $this->db->bind($param, $value);
            }

            return (bool) $this->db->execute();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getUsersByInstitution(int $institutionId): array
    {
        $sql = "
            SELECT ui.*, u.name, u.lastname, u.email, u.phone, u.level
            FROM {$this->table} ui
            JOIN users u ON ui.user_id = u.id
            WHERE ui.institution_id = :institution_id 
              AND ui.is_active = 1
        ";
        
        $this->db->query($sql);
        $this->db->bind(":institution_id", $institutionId);
        
        return $this->db->fetchAll();
    }

    public function getAllUserInstitutions(int $userId): array
    {
        $this->db->query("
            SELECT DISTINCT
                ui.institution_id as working_institution_id,
                ip_primary.company_name,
                'primary' as relationship_type,
                ui.created_at,
                NULL as role_id,
                NULL as role_name,
                NULL as hourly_rate
            FROM {$this->table} ui
            LEFT JOIN institution_profile ip_primary ON ui.institution_id = ip_primary.id
            WHERE ui.user_id = :user_id AND ui.is_active = 1 AND ui.secondary_institution_id IS NULL
            
            UNION ALL
            
            SELECT DISTINCT
                ui.secondary_institution_id as working_institution_id,
                ip_secondary.company_name,
                'secondary' as relationship_type,
                ui.created_at,
                ui.role_id,
                r.name as role_name,
                ui.hourly_rate
            FROM {$this->table} ui
            LEFT JOIN institution_profile ip_secondary ON ui.secondary_institution_id = ip_secondary.id
            LEFT JOIN roles r ON ui.role_id = r.id
            WHERE ui.user_id = :user_id AND ui.is_active = 1 AND ui.secondary_institution_id IS NOT NULL
            
            ORDER BY created_at DESC
        ");
        $this->db->bind(":user_id", $userId);
        
        return $this->db->fetchAll();
    }

    public function getInstitutionsByUser(int $userId): array
    {
        $this->db->query("
            SELECT 
                ui.*,
                ip.company_name,
                ip.email as institution_email,
                CASE 
                    WHEN ui.secondary_institution_id IS NULL THEN 'primary'
                    ELSE 'secondary'
                END as relationship_type
            FROM {$this->table} ui
            LEFT JOIN institution_profile ip ON ui.institution_id = ip.id
            WHERE ui.user_id = :user_id AND ui.is_active = 1
            ORDER BY ui.created_at DESC
        ");
        $this->db->bind(":user_id", $userId);
        
        return $this->db->fetchAll();
    }

    public function linkUserToSecondaryInstitution(int $userId, int $primaryInstitutionId, int $secondaryInstitutionId, ?int $roleId = null, ?float $hourlyRate = null, ?string $contractDetail = null): bool
    {
        try {
            $this->db->query("
                INSERT INTO {$this->table} (user_id, institution_id, secondary_institution_id, role_id, hourly_rate, contract_detail, is_active, created_at, updated_at)
                VALUES (:user_id, :primary_institution_id, :secondary_institution_id, :role_id, :hourly_rate, :contract_detail, 1, NOW(), NOW())
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":primary_institution_id", $primaryInstitutionId);
            $this->db->bind(":secondary_institution_id", $secondaryInstitutionId);
            $this->db->bind(":role_id", $roleId);
            $this->db->bind(":hourly_rate", $hourlyRate);
            $this->db->bind(":contract_detail", $contractDetail);
            
            $result = $this->db->execute();
            $lastInsertId = $this->db->lastId();
            
            return $lastInsertId > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getUserInstitutionRecord(int $userId, int $institutionId): ?object
    {
        $this->db->query("
            SELECT ui.*, r.name as role_name
            FROM {$this->table} ui
            LEFT JOIN roles r ON ui.role_id = r.id
            WHERE ui.user_id = :user_id 
              AND (ui.institution_id = :institution_id OR ui.secondary_institution_id = :institution_id)
              AND ui.is_active = 1
            LIMIT 1
        ");
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":institution_id", $institutionId);
        
        return $this->db->fetchOne() ?: null;
    }

    public function existsSecondaryRelationship(int $userId, int $secondaryInstitutionId): bool
    {
        $this->db->query("
            SELECT id 
            FROM {$this->table} 
            WHERE user_id = :user_id 
              AND secondary_institution_id = :secondary_institution_id 
              AND is_active = 1
            LIMIT 1
        ");
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":secondary_institution_id", $secondaryInstitutionId);
        
        return $this->db->count() > 0;
    }

    public function existsExactRelationship(int $userId, int $primaryInstitutionId, int $secondaryInstitutionId): bool
    {
        $this->db->query("
            SELECT id 
            FROM {$this->table} 
            WHERE user_id = :user_id 
              AND institution_id = :primary_institution_id 
              AND secondary_institution_id = :secondary_institution_id 
              AND is_active = 1
            LIMIT 1
        ");
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":primary_institution_id", $primaryInstitutionId);
        $this->db->bind(":secondary_institution_id", $secondaryInstitutionId);
        
        return $this->db->count() > 0;
    }

    public function getUserPrimaryInstitution(int $userId): ?object
    {
        // First try to find a record where secondary_institution_id IS NULL
        $this->db->query("
            SELECT * 
            FROM {$this->table} 
            WHERE user_id = :user_id 
              AND secondary_institution_id IS NULL 
              AND is_active = 1
            LIMIT 1
        ");
        $this->db->bind(":user_id", $userId);
        
        $result = $this->db->fetchOne();
        
        if (!$result) {
            $this->db->query("
                SELECT * 
                FROM {$this->table} 
                WHERE user_id = :user_id 
                  AND is_active = 1
                ORDER BY created_at ASC
                LIMIT 1
            ");
            $this->db->bind(":user_id", $userId);
            
            $result = $this->db->fetchOne();
        }
        
        return $result ?: null;
    }

    public function getUserSecondaryInstitutions(int $userId): array
    {
        $this->db->query("
            SELECT ui.*, ip.company_name
            FROM {$this->table} ui
            LEFT JOIN institution_profile ip ON ui.secondary_institution_id = ip.id
            WHERE ui.user_id = :user_id 
              AND ui.secondary_institution_id IS NOT NULL 
              AND ui.is_active = 1
            ORDER BY ui.created_at DESC
        ");
        $this->db->bind(":user_id", $userId);
        
        return $this->db->fetchAll();
    }

    public function userBelongsToInstitution(int $userId, int $institutionId): bool
    {
        $sql = "
            SELECT id 
            FROM {$this->table} 
            WHERE user_id = :user_id 
            AND (institution_id = :institution_id OR secondary_institution_id = :institution_id)
            AND is_active = 1
        ";
        
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":institution_id", $institutionId);
        
        $result = $this->db->fetchOne();
        return !empty($result);
    }

    public function getUsersForInstitution(int $institutionId, array $filters = []): array
    {
        $whereConditions = ["u.is_active = 1"];
        $params = [
            ':institution_id1' => $institutionId,
            ':institution_id2' => $institutionId,
            ':institution_id3' => $institutionId,
            ':institution_id4' => $institutionId,
            ':institution_id5' => $institutionId
        ];

        // Name filter
        if (isset($filters['name'])) {
            $whereConditions[] = "(u.name LIKE :name OR u.lastname LIKE :name)";
            $params[':name'] = "%{$filters['name']}%";
        }

        // Email filter
        if (isset($filters['email'])) {
            $whereConditions[] = "u.email LIKE :email";
            $params[':email'] = "%{$filters['email']}%";
        }

        // Level filter
        if (isset($filters['level'])) {
            $whereConditions[] = "u.level = :level";
            $params[':level'] = $filters['level'];
        }

        $whereClause = implode(" AND ", $whereConditions);

        $sql = "
            SELECT u.*, 
                   CASE 
                       WHEN ui.institution_id = :institution_id1 AND ui.secondary_institution_id IS NULL THEN 'primary'
                       WHEN ui.secondary_institution_id = :institution_id2 THEN 'secondary'
                   END as institution_relationship,
                   ui.institution_id as primary_institution_id,
                   ip_primary.company_name as primary_company,
                   ui.secondary_institution_id as linked_institution_id,
                   ip_secondary.company_name as linked_company,
                   ui.is_active as institution_active,
                   ui.id as user_institution_id,
                   ui.role_id as institution_role_id,
                   ui.hourly_rate as institution_hourly_rate,
                   ui.contract_detail,
                   ui.created_at as linked_since,
                   r.name as role_name
            FROM users u
            JOIN user_institutions ui ON u.id = ui.user_id
            LEFT JOIN institution_profile ip_primary ON ui.institution_id = ip_primary.id
            LEFT JOIN institution_profile ip_secondary ON ui.secondary_institution_id = ip_secondary.id
            LEFT JOIN roles r ON ui.role_id = r.id
            WHERE {$whereClause}
            AND (
                (ui.institution_id = :institution_id3 AND ui.secondary_institution_id IS NULL) 
                OR 
                ui.secondary_institution_id = :institution_id4
            )
            AND ui.is_active = 1
            AND ui.id = (
                SELECT ui2.id 
                FROM user_institutions ui2 
                WHERE ui2.user_id = u.id 
                  AND ui2.is_active = 1
                  AND (
                      (ui2.institution_id = :institution_id5 AND ui2.secondary_institution_id IS NULL) 
                      OR 
                      ui2.secondary_institution_id = :institution_id5
                  )
                ORDER BY 
                    CASE 
                        WHEN ui2.institution_id = :institution_id5 AND ui2.secondary_institution_id IS NULL THEN 1
                        WHEN ui2.secondary_institution_id = :institution_id5 THEN 2
                    END
                LIMIT 1
            )
            ORDER BY 
                CASE 
                    WHEN ui.institution_id = :institution_id5 AND ui.secondary_institution_id IS NULL THEN 1
                    WHEN ui.secondary_institution_id = :institution_id5 THEN 2
                END,
                ui.created_at DESC
        ";

        $this->db->query($sql);
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        return $this->db->fetchAll();
    }

    public function removeAllUserInstitutions(int $userId): bool
    {
        try {
            $this->db->query("
                UPDATE {$this->table} 
                SET is_active = 0, updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $this->db->bind(":user_id", $userId);
            
            return (bool)$this->db->execute();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateUserInstitutionData(int $userInstitutionId, array $data): bool
    {
        try {
            $setParts = [];
            $params = [':id' => $userInstitutionId];
            
            foreach ($data as $field => $value) {
                $setParts[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
            
            if (empty($setParts)) {
                return false;
            }
            
            $setClause = implode(', ', $setParts);
            $sql = "UPDATE {$this->table} SET {$setClause}, updated_at = NOW() WHERE id = :id";
            
            $this->db->query($sql);
            
            foreach ($params as $param => $value) {
                $this->db->bind($param, $value);
            }
            
            $this->db->execute();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getInactivePrimaryInstitution(int $userId): ?object
    {
        // Find a record where secondary_institution_id IS NULL and is_active = 0
        $this->db->query("
            SELECT * 
            FROM {$this->table} 
            WHERE user_id = :user_id 
              AND secondary_institution_id IS NULL 
              AND is_active = 0
            LIMIT 1
        ");
        $this->db->bind(":user_id", $userId);
        
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function reactivateUserInstitution(int $userInstitutionId): bool
    {
        try {
            $this->db->query("
                UPDATE {$this->table} 
                SET is_active = 1, updated_at = NOW() 
                WHERE id = :id
            ");
            $this->db->bind(":id", $userInstitutionId);
            
            $this->db->execute();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}


