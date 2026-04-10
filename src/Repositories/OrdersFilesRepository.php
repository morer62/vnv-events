<?php

namespace App\Repositories;

class OrdersFilesRepository extends BaseRepository
{
    public function __construct() {
        $this->db = new Connection();
        $this->table = "orders_files";
    }
}
