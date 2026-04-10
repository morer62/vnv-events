<?php

namespace App\Repositories;

class ServicePromotionsRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "service_promotions";
        $this->db = new Connection();
    }
}