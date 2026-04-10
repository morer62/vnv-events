<?php

namespace App\Repositories;

use App\Repositories\BaseRepository;


class ClientsRequestRepository extends BaseRepository
{ 
    public function __construct()
    {
        $this->table = "clients_request";
        $this->db = new Connection();

        
   
        $this->fields = [
            'profile_cat', 'profile_id', 'event_date', 'event_time', 
            'event_duration', 'guests', 'budget', 'event_type', 
            'details', 'client_name', 'client_phone', 'client_email', 
            'client_address', 'created_at', 'status'
        ];
    }
}