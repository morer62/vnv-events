<?php

namespace App\Repositories;

class OrdersAcceptanceContractTemplateRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_acceptance_contract_template";
        $this->db = new Connection();
    }

    public function getByOwner(int $ownerId): ?object
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner LIMIT 1");
        $this->db->bind(":id_owner", $ownerId);
        
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getOrCreateByOwner(int $ownerId): object
    {
        $template = $this->getByOwner($ownerId);
        
        if (!$template) {
            $defaultContent = "I hereby accept and confirm that I have received the order in accordance with the amount paid. I acknowledge that the services and items corresponding to the order have been received or are agreed as delivered as per the payment made.";
            
            $this->add([
                "id_owner" => $ownerId,
                "content" => $defaultContent
            ]);
            
            $template = $this->getByOwner($ownerId);
        }
        
        return $template;
    }

    public function updateByOwner(int $ownerId, string $content): bool
    {
        $template = $this->getByOwner($ownerId);
        
        if ($template) {
            return $this->update(
                ["content" => $content],
                ["id_owner" => $ownerId]
            );
        } else {
            return $this->add([
                "id_owner" => $ownerId,
                "content" => $content
            ]);
        }
    }
}
