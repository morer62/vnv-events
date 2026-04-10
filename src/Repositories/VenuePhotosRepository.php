<?php

namespace App\Repositories;

class VenuePhotosRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "venue_photos";
        $this->db = new Connection();
    }
}