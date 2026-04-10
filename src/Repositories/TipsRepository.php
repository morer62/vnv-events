<?php

namespace App\Repositories;

class TipsRepository extends BaseRepository
{
    protected string $table = "tips";

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getActiveTips()
    {
        return $this->getAllBy(["is_active" => 1]);
    }
}

