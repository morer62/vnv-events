<?php

namespace App\Repositories;

use App\Repositories\BaseRepository;

class ClientsUsersRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "clients_users";
        $this->fields = ["client_id", "id_owner_asociated", "created_at"];
        $this->db = new Connection();
    }

    public function create(int $clientId, int $ownerId): void
    {
        if ($this->exists($clientId, $ownerId)) {
            return;
        }

        $this->db->query("
            INSERT INTO {$this->table} (client_id, id_owner_asociated)
            VALUES (:client_id, :id_owner_asociated)
        ");
        $this->db->bind(":client_id", $clientId);
        $this->db->bind(":id_owner_asociated", $ownerId);
        $this->db->execute();
    }

    public function exists(int $clientId, int $ownerId): bool
    {
        $this->db->query("
            SELECT id 
            FROM {$this->table} 
            WHERE client_id = :client_id 
              AND id_owner_asociated = :id_owner_asociated 
            LIMIT 1
        ");
        $this->db->bind(":client_id", $clientId);
        $this->db->bind(":id_owner_asociated", $ownerId);
        $result = $this->db->count();
        return $result > 0;
    }

    public function getClientIdsByUserId(int $ownerId): array
    {
        $this->db->query("
            SELECT client_id 
            FROM {$this->table} 
            WHERE id_owner_asociated = :owner
        ");
        $this->db->bind(":owner", $ownerId);
        $result = $this->db->fetchAll();
        return array_column($result, "client_id");
    }

    public function getOwnerIdsForClient(int $clientId): array
    {
        $this->db->query("SELECT id_owner_asociated  FROM clients_users WHERE client_id = :id");
        $this->db->bind(":id", $clientId);
        $rows = $this->db->fetchAll();
  
        return array_map(fn($row) => (int) $row->id_owner_asociated, $rows);

    }

    public function getAssociatedCompaniesForClient(int $clientId): array
    {
        $this->db->query("
            SELECT
                cu.id,
                cu.client_id,
                cu.id_owner_asociated AS owner_id,
                ip.id AS institution_id,
                ip.company_name,
                ip.logo_path,
                ip.email,
                ip.phone,
                ip.address_line1,
                ip.city,
                ip.state,
                ip.zip,
                ip.country,
                ip.payment_method_accepted,
                cu.created_at
            FROM {$this->table} cu
            LEFT JOIN institution_profile ip ON ip.id_owner = cu.id_owner_asociated
            WHERE cu.client_id = :client_id
            ORDER BY COALESCE(ip.company_name, '') ASC, cu.created_at DESC
        ");
        $this->db->bind(":client_id", $clientId);

        return $this->db->fetchAll();
    }

    public function deleteRelation(int $clientId, int $ownerId): void
    {
        $this->db->query("
            DELETE FROM {$this->table} 
            WHERE client_id = :client AND id_owner_asociated = :owner
        ");
        $this->db->bind(":client", $clientId);
        $this->db->bind(":owner", $ownerId);
        $this->db->execute();
    }

    public function getClientsByOwner(int $ownerId, array $filters = []): array
    {
        $sql = "
            SELECT 
                u.id,
                u.name,
                u.lastname,
                u.email,
                u.phone,
                u.phone_code,
                u.level,
                u.is_active,
                u.password_updated
            FROM {$this->table} cu
            JOIN users u ON cu.client_id = u.id
            WHERE cu.id_owner_asociated = :owner
            AND u.is_active = 1
        ";
        
        $params = [':owner' => $ownerId];
        
        if (!empty($filters["name"])) {
            $sql .= " AND (u.name LIKE :name OR u.lastname LIKE :name)";
            $params[':name'] = '%' . $filters["name"] . '%';
        }
        
        if (!empty($filters["email"])) {
            $sql .= " AND u.email LIKE :email";
            $params[':email'] = '%' . $filters["email"] . '%';
        }
        
        $sql .= " ORDER BY u.name ASC, u.lastname ASC";
        
        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        return $this->db->fetchAll();
    }
}
