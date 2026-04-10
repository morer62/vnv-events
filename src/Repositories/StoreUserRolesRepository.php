<?php

namespace App\Repositories;

class StoreUserRolesRepository extends BaseRepository
{
    protected string $table = 'store_user_roles';

    protected array $fields = [
        'id',
        'id_owner',
        'id_user',
        'role',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getById(int $id)
    {
        return $this->getOne([
            'id' => $id
        ]);
    }

    public function getByOwnerAndUser(int $idOwner, int $idUser)
    {
        return $this->getOne([
            'id_owner' => $idOwner,
            'id_user' => $idUser
        ]);
    }

    public function getByUser(int $idUser)
    {
        return $this->getAllBy([
            'id_user' => $idUser
        ]);
    }

    public function getAllByOwner(int $idOwner)
    {
        return $this->getAllBy([
            'id_owner' => $idOwner
        ]);
    }

    public function getAllByOwnerAndRole(int $idOwner, string $role)
    {
        return $this->getAllBy([
            'id_owner' => $idOwner,
            'role' => $role
        ]);
    }

    public function getUsersByOwnerAndRole(int $idOwner, string $role): array
    {
        $this->db->query("
            SELECT sur.id_user, u.name, u.lastname, u.email
            FROM {$this->table} sur
            INNER JOIN users u ON u.id = sur.id_user
            WHERE sur.id_owner = :id_owner
              AND sur.role = :role
              AND u.is_active = 1
            ORDER BY u.name ASC, u.lastname ASC
        ");
        $this->db->bind(':id_owner', $idOwner, \PDO::PARAM_INT);
        $this->db->bind(':role', strtolower(trim($role)));
        return $this->db->fetchAll();
    }

    public function getRoleValueByOwnerAndUser(int $idOwner, int $idUser): ?string
    {
        $row = $this->getByOwnerAndUser($idOwner, $idUser);

        if (!$row) {
            return null;
        }

        return isset($row->role) ? (string)$row->role : null;
    }

    public function hasRole(int $idOwner, int $idUser, string $role): bool
    {
        $currentRole = $this->getRoleValueByOwnerAndUser($idOwner, $idUser);

        if (!$currentRole) {
            return false;
        }

        return strtolower(trim($currentRole)) === strtolower(trim($role));
    }

    public function saveRole(int $idOwner, int $idUser, string $role)
    {
        $role = trim($role) !== '' ? trim($role) : 'general';

        $existing = $this->getByOwnerAndUser($idOwner, $idUser);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->update([
                'role' => $role,
                'updated_at' => $now
            ], [
                'id' => $existing->id
            ]);
        }

        return $this->add([
            'id_owner' => $idOwner,
            'id_user' => $idUser,
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    public function setGeneralRole(int $idOwner, int $idUser)
    {
        return $this->saveRole($idOwner, $idUser, 'general');
    }

    public function setKitchenRole(int $idOwner, int $idUser)
    {
        return $this->saveRole($idOwner, $idUser, 'kitchen');
    }

    public function setDeliveryRole(int $idOwner, int $idUser)
    {
        return $this->saveRole($idOwner, $idUser, 'delivery');
    }

    public function deleteByOwnerAndUser(int $idOwner, int $idUser)
    {
        return $this->delete([
            'id_owner' => $idOwner,
            'id_user' => $idUser
        ]);
    }

    public function getAllowedRoles(): array
    {
        return [
            'general',
            'kitchen',
            'delivery'
        ];
    }

    public function isValidRole(string $role): bool
    {
        return in_array(strtolower(trim($role)), $this->getAllowedRoles(), true);
    }

    public function saveValidatedRole(int $idOwner, int $idUser, string $role)
    {
        $role = strtolower(trim($role));

        if (!$this->isValidRole($role)) {
            $role = 'general';
        }

        return $this->saveRole($idOwner, $idUser, $role);
    }
}