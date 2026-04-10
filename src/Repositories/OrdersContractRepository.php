<?php

namespace App\Repositories;

class OrdersContractRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_contracts";
        $this->db = new Connection();
    }

    public function getAllByInstitutionOwner(int $institutionOwnerId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner ORDER BY id DESC");
        $this->db->bind(":id_owner", $institutionOwnerId);
        
        return $this->db->fetchAll();
    }

    public function getOneByIdAndOwner(int $id, int $institutionOwnerId): ?object
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id` = :id AND `id_owner` = :id_owner LIMIT 1");
        $this->db->bind(":id", $id);
        $this->db->bind(":id_owner", $institutionOwnerId);
        
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function addWithExplicitOwner(array $data): bool
    {
        try {
            $fields = array_keys($data);
            $placeholders = array_map(fn($field) => ":$field", $fields);
            
            $sql = "INSERT INTO `{$this->table}` (`" . implode('`, `', $fields) . "`) 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $this->db->query($sql);
            
            foreach ($data as $field => $value) {
                $this->db->bind(":$field", $value);
            }
            
            $this->db->execute();
            return true;
        } catch (\Exception $e) {
            error_log("Error in addWithExplicitOwner: " . $e->getMessage());
            return false;
        }
    }

    public function getByIdWithoutOwnershipCheck(int $id): ?object
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $this->db->query($sql);
        $this->db->bind(":id", $id);
        $result = $this->db->fetchOne();
        return $result ? (object)$result : null;
    }
}
