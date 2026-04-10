<?php

namespace App\Repositories;

class DocumentsLogsRepository extends BaseRepository
{
    protected array $fields = [
        "id_order", "id_user", "doc_type", "file_path", "hash", 
        "ip", "user_agent", "extra", "generated_at"
    ];

    public function __construct()
    {
        $this->table = "document_logs";
        $this->db = new Connection();
    }

    public function getAllByOrder(int $id_order): array
    {
        return $this->getAllBy(["id_order" => $id_order]);
    }

    public function getAllByUser(int $id_user): array
    {
        return $this->getAll(["id_user" => $id_user]);
    }

    public function getByType(int $id_order, string $type): ?object
    {
        return $this->getOne([
            "id_order" => $id_order,
            "doc_type" => $type
        ]);
    }
}
