<?php

namespace App\Repositories;

class VenuePromotionsRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "venue_promotions";
        $this->db = new Connection();
    }
}