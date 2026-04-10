<?php

namespace App\Repositories;

class ServicePhotosRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "service_photos";
        $this->db = new Connection();
    }
}