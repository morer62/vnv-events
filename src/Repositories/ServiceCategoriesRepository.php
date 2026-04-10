<?php

namespace App\Repositories;

class ServiceCategoriesRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "service_category";
        $this->db = new Connection();
    }
}