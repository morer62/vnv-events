<?php

namespace App\Repositories;

class StorageItemRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "storage_items";
        $this->db = new Connection();
    }

    public function searchByName(int $userId, string $term): array
    {
        $this->db->query("
            SELECT c.id AS container_id, c.name AS container_name, i.name AS item_name, i.quantity
            FROM storage_items i
            JOIN storage_containers c ON i.id_container = c.id
            WHERE i.name LIKE :term and c.id_owner = :userId
            ORDER BY c.name ASC
        ");
        $this->db->bind(":term", "%{$term}%");
        $this->db->bind(":userId", $userId);
        return $this->db->fetchAll();
    }


    
}


 
