<?php

namespace App\Repositories;

class PaymentsServicesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "payments_service_zip_codes";
        $this->db = new Connection();
    }

}
