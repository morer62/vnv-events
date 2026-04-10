<?php

namespace App\Repositories;

class CrmWhatsappMessagesRepository extends BaseRepository
{
    protected array $fields = [
        'id_lead', 'message', 'media_url', 'media_type', 'direction'
    ];

    public function __construct()
    {
        $this->table = "crm_whatsapp_messages";
        $this->db = new Connection();
    }

    public function getByLeadId(int $leadId): array
    {
        return $this->getAllBy(["id_lead" => $leadId]);
    }
}
