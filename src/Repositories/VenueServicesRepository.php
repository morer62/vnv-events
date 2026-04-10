<?php

namespace App\Repositories;

class VenueServicesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "venue_services";
        $this->db = new Connection();
    }
}
