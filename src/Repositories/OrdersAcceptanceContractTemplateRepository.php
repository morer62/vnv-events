<?php

namespace App\Repositories;

use App\Services\TranslationService;
use Throwable;

class OrdersAcceptanceContractTemplateRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_acceptance_contract_template";
        $this->db = new Connection();
    }

    public function getOrCreateByOwner(int $ownerId): object
    {
        $defaultContent = TranslationService::trans('planner_hub.accept_order_confirmation');

        try {
            $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner LIMIT 1");
            $this->db->bind(":id_owner", $ownerId);
            $existing = $this->db->fetchOne();

            if ($existing) {
                return $existing;
            }

            $this->db->query("
                INSERT INTO `{$this->table}` (`id_owner`, `content`, `created_at`, `updated_at`)
                VALUES (:id_owner, :content, NOW(), NOW())
            ");
            $this->db->bind(":id_owner", $ownerId);
            $this->db->bind(":content", $defaultContent);
            $this->db->execute();

            return (object)[
                "id_owner" => $ownerId,
                "content" => $defaultContent,
            ];
        } catch (Throwable $e) {
            error_log("OrdersAcceptanceContractTemplateRepository fallback: " . $e->getMessage());

            return (object)[
                "id_owner" => $ownerId,
                "content" => $defaultContent,
            ];
        }
    }
}
