<?php

namespace App\Repositories;

use App\Entity\UserPermissions2;

class UserRolesRepository extends BaseRepository
{
    protected array $fields = ['user_id', 'role_id'];

    public function __construct()
    {
        $this->table = "user_roles";
        $this->db = new Connection();
    }

    public function getUserRoleAndPermissions(int $userId, ?int $institutionId = null): array|null
    {
        $roleId = null;

        if ($institutionId !== null) {
            $this->db->query("
                SELECT role_id 
                FROM user_institutions 
                WHERE user_id = :userId 
                AND (institution_id = :institutionId OR secondary_institution_id = :institutionId)
                AND is_active = 1
                LIMIT 1
            ");
            $this->db->bind(":userId", $userId);
            $this->db->bind(":institutionId", $institutionId);
            
            $userInstitution = $this->db->fetchOne();
            
            if ($userInstitution && $userInstitution->role_id) {
                $roleId = $userInstitution->role_id;
            }
        }

        if (!$roleId) {
            $userRole = $this->getOne(["user_id" => $userId]);
            
            if (!$userRole) {
                return [];
            }
            
            $roleId = $userRole->role_id;
        }

        $this->db->query("
            SELECT p.* 
            FROM role_permissions rp 
            INNER JOIN permissions p ON rp.permission_id = p.id 
            WHERE rp.role_id = :roleId
        ");
        $this->db->bind(":roleId", $roleId);

        $data = $this->db->fetchAll();

        return array_map(function ($item) {
            return new UserPermissions2($item->module, $item->action);
        }, $data);
    }
}
