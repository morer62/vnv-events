<?php

namespace App\Repositories;

class OrdersServiceRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_services";
        $this->db = new Connection();
    }

    public function getAllSortedByName(): array
    {
        $this->db->query("SELECT * FROM `$this->table` ORDER BY name ASC");
        return $this->db->fetchAll();
    }

    public function archive(int $id)
    {
        $sql = "UPDATE {$this->table} SET is_archived = 1 WHERE id = :id";
        $this->db->query($sql);
        $this->db->bind(":id", $id);
        $this->db->execute();
    }

    public function getByIdWithoutOwnershipCheck(int $id): ?object
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $this->db->query($sql);
        $this->db->bind(":id", $id);
        $result = $this->db->fetchOne();
        return $result ? (object)$result : null;
    }

    public function getAllBy(array $criteriaVals, array $columns = [], int $limit = 0): array
    {
        try {
            $columnsSQL = count($columns) > 0 ? implode(',', $columns) : '*';
            $criteria = $criteriaVals;

            $keys = array_keys($criteria);
            $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));
            $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where";

            if ($limit > 0) {
                $query .= " LIMIT $limit";
            }

            $this->db->query($query);
            foreach ($criteria as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            return $this->db->fetchAll();
        } catch (\PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return [];
        }
    }

    public function getAllByInstitutionOwner(int $institutionOwnerId, int $isArchived = 0): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner AND `is_archived` = :is_archived ORDER BY name ASC");
        $this->db->bind(":id_owner", $institutionOwnerId);
        $this->db->bind(":is_archived", $isArchived);
        
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

}
