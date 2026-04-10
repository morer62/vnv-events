<?php

namespace App\Repositories;

class StorageContainerRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "storage_containers";
        $this->db = new Connection();
    }
}
