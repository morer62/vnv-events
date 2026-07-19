<?php

namespace App\Repositories;

class PayrollTimeLogsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "payroll_hours";
        $this->db = new Connection();
    }

    public function getActiveLog(int $userId): ?array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_user = :id_user AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $this->db->bind(":id_user", $userId);
        return $this->db->fetchAll();
    }

    public function startNow(int $userId, int $ownerId, ?string $lat = null, ?string $lng = null): void
    {
        $this->db->query("INSERT INTO {$this->table} (id_user, start_time, end_time, is_paid, location_lat, location_long, id_owner) VALUES (:id_user, NOW(), NULL, 0, :lat, :lng, :owner)");
        $this->db->bind(":id_user", $userId);
        $this->db->bind(":lat", $lat);
        $this->db->bind(":lng", $lng);
        $this->db->bind(":owner", $ownerId);
        $this->db->execute();
    }

    public function stopNow(int $logId, ?string $lat = null, ?string $lng = null, ?string $notes = null): void
    {
        $this->db->query("UPDATE {$this->table} SET end_time = NOW(), location_lat = :lat, location_long = :lng, notes = :notes WHERE id = :id");
        $this->db->bind(":lat", $lat);
        $this->db->bind(":lng", $lng);
        $this->db->bind(":notes", $notes);
        $this->db->bind(":id", $logId);
        $this->db->execute();
    }

    public function getActiveLogsByUserAndOwner(int $userId, int $ownerId): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_user = :id_user AND id_owner = :id_owner AND end_time IS NULL ORDER BY start_time DESC");
        $this->db->bind(":id_user", $userId);
        $this->db->bind(":id_owner", $ownerId);
        return $this->db->fetchAll();
    }

    public function getRecentLogsByUserAndOwner(int $userId, int $ownerId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $this->db->query("SELECT *, CASE WHEN end_time IS NULL THEN TIMESTAMPDIFF(SECOND,start_time,NOW()) ELSE TIMESTAMPDIFF(SECOND,start_time,end_time) END AS duration_seconds FROM {$this->table} WHERE id_user=:id_user AND id_owner=:id_owner ORDER BY start_time DESC LIMIT {$limit}");
        $this->db->bind(':id_user', $userId);
        $this->db->bind(':id_owner', $ownerId);
        return $this->db->fetchAll();
    }
}
