<?php

namespace App\Repositories;

class CrmCategoryRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "crm_categories";
        $this->db = new Connection();
    }

    public function getAllIndexedById(): array {
        $items = $this->getAll();
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->id] = $item;
        }
        return $indexed;
    }

    public function getAllByInstitutionOwner(int $institutionOwnerId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner");
        $this->db->bind(":id_owner", $institutionOwnerId);
        return $this->db->fetchAll();
    }

    public function addWithExplicitOwner(array $data): bool
    {
        try {
            $keys = array_keys($data);
            $insert = "(";
            $values = "(";
    
            for ($i = 0; $i < count($keys); $i++) {
                $insert .= " `$keys[$i]`";
                $values .= " :$keys[$i]";
                if ($i != count($keys) - 1) {
                    $insert .= ", ";
                    $values .= ", ";
                }
            }
    
            $insert .= ")";
            $values .= ")";
    
            $query = "INSERT INTO `$this->table` $insert VALUES $values";
    
            $this->db->query($query);
            for ($i = 0; $i < count($keys); $i++) {
                $this->db->bind(":$keys[$i]", $data[$keys[$i]]);
            }
            $this->db->execute();
    
            return true;
        } catch (\PDOException $th) {
            error_log("Error in addWithExplicitOwner: " . $th->getMessage());
            return false;
        }
    }

    public function getOneByIdAndOwner(int $id, int $institutionOwnerId): ?object
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id` = :id AND `id_owner` = :id_owner");
        $this->db->bind(":id", $id);
        $this->db->bind(":id_owner", $institutionOwnerId);
        
        $result = $this->db->fetchOne();
        return !$result ? null : $result;
    }

}
