<?php

namespace App\Repositories;

final class OrderExecutionFilesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = 'order_execution_files';
    }
}
