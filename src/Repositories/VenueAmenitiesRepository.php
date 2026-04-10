<?php

namespace App\Repositories;

class VenueAmenitiesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "venue_amenities";
        $this->db = new Connection();
    }
}
