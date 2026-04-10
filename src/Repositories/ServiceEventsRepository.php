<?php

namespace App\Repositories;

class ServiceEventsRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "service_events";
        $this->db = new Connection();
    }
}