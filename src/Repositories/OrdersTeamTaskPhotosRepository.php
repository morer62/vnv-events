<?php

namespace App\Repositories;

class OrdersTeamTaskPhotosRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = "orders_team_task_photos";
    }

    public function getByTask(int $taskId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_task` = :taskId ORDER BY `uploaded_at` DESC");
        $this->db->bind(":taskId", $taskId);
        return $this->db->fetchAll();
    }

    public function getByUploader(int $userId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `uploaded_by` = :userId ORDER BY `uploaded_at` DESC");
        $this->db->bind(":userId", $userId);
        return $this->db->fetchAll();
    }
}
