<?php

namespace App\Repositories;

class WordpressSyncOriginsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "wordpress_sync_origins";
        $this->db = new Connection();
    }
}

