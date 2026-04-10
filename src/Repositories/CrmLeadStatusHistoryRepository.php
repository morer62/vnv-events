<?php

namespace App\Repositories;

class CrmLeadStatusHistoryRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = "crm_lead_status_history";
    }

    public function getByLeadId(int $leadId): array
    {
        return $this->getAllBy(["id_lead" => $leadId]);
    }
}
