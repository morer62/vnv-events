<?php

namespace App\Repositories;

class VenueCategoriesRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "venue_categories";
        $this->db = new Connection();
    }
}