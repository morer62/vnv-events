<?php

namespace App\Repositories;

use App\Entity\UserPermissions;

class UserFeaturePermissionsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = 'user_feature_permissions';
        $this->db = new Connection();
    }

    public function getAllByUserId(int $userId): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_user = :id_user");
        $this->db->bind(':id_user', $userId);
        return $this->db->fetchAll();
    }

    public function getSlugsByUserId(int $userId): array
    {
        $this->db->query("SELECT feature_slug FROM {$this->table} WHERE id_user = :id_user");
        $this->db->bind(':id_user', $userId);
        $results = $this->db->fetchAll();

        return array_column($results, 'feature_slug');
    }

    public function addPermission(int $userId, string $slug): void
    {
        $this->db->query("INSERT INTO {$this->table} (id_user, feature_slug) VALUES (:id_user, :slug)");
        $this->db->bind(':id_user', $userId);
        $this->db->bind(':slug', $slug);
        $this->db->execute();
    }

    public function replaceManagementModules(int $userId, array $slugs): void
    {
        $allowed = array_flip(UserPermissions::managementModuleSlugs());
        $selected = [];
        foreach ($slugs as $slug) {
            $slug = strtolower(trim((string)$slug));
            if (isset($allowed[$slug])) {
                $selected[$slug] = true;
            }
        }

        $this->db->beginTransaction();
        try {
            // Center delegation is controlled separately by VNV direction. A normal
            // management form may only replace the permissions it presents.
            $managed = array_merge(array_keys($allowed), [UserPermissions::MANAGEMENT_CONFIGURED, UserPermissions::CRM]);
            $parameters = [];
            foreach ($managed as $index => $slug) {
                $parameters[':scope'.$index] = $slug;
            }
            $in = implode(',', array_keys($parameters));
            $this->db->query("DELETE FROM {$this->table} WHERE id_user=:user AND feature_slug IN ($in)");
            $this->db->bind(':user', $userId);
            foreach ($parameters as $parameter => $value) {
                $this->db->bind($parameter, $value);
            }
            $this->db->execute();
            $this->addPermission($userId, UserPermissions::MANAGEMENT_CONFIGURED);
            foreach (array_keys($selected) as $slug) {
                $this->addPermission($userId, $slug);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function removeAllByUserId(int $userId): void
    {
        $this->db->query("DELETE FROM {$this->table} WHERE id_user = :id_user");
        $this->db->bind(':id_user', $userId);
        $this->db->execute();
    }

    public function getByUserId(int $userId): array
    {
        $this->db->query("SELECT feature_slug FROM {$this->table} WHERE id_user = :id_user");
        $this->db->bind(":id_user", $userId);
        return array_column($this->db->fetchAll(), 'feature_slug');
    }

    public function deleteByUserId(int $userId): void
    {
        $this->db->query("DELETE FROM {$this->table} WHERE id_user = :id_user");
        $this->db->bind(":id_user", $userId);
        $this->db->execute();
    }
}
