<?php

namespace App\Repositories;

class LeadsCollectionsRepository extends BaseRepository
{

    public function __construct() {
        $this->db = new Connection();
        $this->table = "leads_collections";
    }

    public function getAllSortedByName(): array
    {
        $this->db->query("SELECT * FROM `$this->table` ORDER BY name ASC");
        return $this->db->fetchAll();
    }


    public function getAllWithItemCount(): array
    {
        $sql = "
            SELECT c.*, COUNT(i.id) AS total_items
            FROM leads_collections c
            LEFT JOIN leads_collections_items i ON i.collection_id = c.id
            GROUP BY c.id
            ORDER BY c.name ASC
        ";

        $this->db->query($sql);
        return $this->db->fetchAll();
    }




}
