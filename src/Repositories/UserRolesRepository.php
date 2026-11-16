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
        $permissions = [];

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
            if ($userRole) {
                $roleId = $userRole->role_id;
            }
        }

        if ($roleId) {
            $this->db->query("
                SELECT p.*
                FROM role_permissions rp
                INNER JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = :roleId
            ");
            $this->db->bind(":roleId", $roleId);
            foreach ($this->db->fetchAll() as $item) {
                $permissions[] = new UserPermissions2($item->module, $item->action);
            }
        }

        // Per-user management access supplements the operational role. The
        // feature_full marker lets User distinguish explicit Level 1 grants
        // from older role permissions and keeps existing accounts compatible.
        try {
            $featureRepository = new UserFeaturePermissionsRepository();
            foreach ($featureRepository->getSlugsByUserId($userId) as $slug) {
                $permissions[] = new UserPermissions2((string)$slug, 'feature_full');
            }
        } catch (\Throwable $exception) {
            error_log('Unable to load user management modules: ' . $exception->getMessage());
        }

        return $permissions;
    }
}
