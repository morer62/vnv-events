<?php

namespace App\Repositories;

class RolesRepository extends BaseRepository
{
    protected array $fields = ['name'];

    public function __construct()
    {
        $this->table = "roles";
        $this->db = new Connection();
    }

    public function getPermissionIds(int $role_id): array
    {
        $sql = "SELECT permission_id FROM role_permissions WHERE role_id = ?";
        $this->db->query($sql);
        $this->db->bind(1, $role_id);
        $rows = $this->db->fetchAll();

        return array_column($rows, 'permission_id');
    }

    public function updatePermissions(int $role_id, array $permission_ids): bool
    {
        try {
            $this->db->query("DELETE FROM role_permissions WHERE role_id = ?");
            $this->db->bind(1, $role_id);
            $this->db->execute();

            foreach ($permission_ids as $perm_id) {
                $this->db->query("INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");
                $this->db->bind(":role_id", $role_id);
                $this->db->bind(":permission_id", $perm_id);
                $this->db->execute();
            }

            return true;
        } catch (\PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return false;
        }
    }
}
