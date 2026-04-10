<?php

namespace App\Repositories;

class VenueAvailabilityRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "venue_availability";
        $this->db = new Connection();
    }
}
