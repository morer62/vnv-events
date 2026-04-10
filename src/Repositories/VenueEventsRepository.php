<?php

namespace App\Repositories;

class VenueEventsRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "venue_events";
        $this->db = new Connection();
    }
}