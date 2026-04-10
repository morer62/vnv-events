<?php

namespace App\Repositories;

class LeadsCollectionsItemsRepository extends BaseRepository
{

    public function __construct() {
        $this->db = new Connection();
        $this->table = "leads_collections_items";
    }

    public function getAllSortedByName(): array
    {
        $this->db->query("SELECT * FROM `$this->table` ORDER BY name ASC");
        return $this->db->fetchAll();
    }



}
