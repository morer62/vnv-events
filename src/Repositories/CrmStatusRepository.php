<?php

namespace App\Repositories;

class CrmStatusRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "crm_status";
        $this->db = new Connection();
    }

     

    public function getNameById(int $id): ?string
    {
        $record = $this->getOne(["id" => $id]);
        return $record ? $record->name : null;
    }
}



