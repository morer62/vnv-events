<?php

namespace App\Repositories;

class EventTablesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "event_tables";
        $this->db = new Connection();
    }

    public function getAllByEvent(int $eventId): array
    {
        $this->db->query("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM event_guests WHERE id_event = t.id_event AND table_number = t.table_number) as assigned_guests
            FROM {$this->table} t
            WHERE t.id_event = :event_id
            ORDER BY t.table_number ASC
        ");
        $this->db->bind(':event_id', $eventId);
        return $this->db->fetchAll();
    }

    public function updatePositions(array $positions): bool
    {
        foreach ($positions as $position) {
            $this->db->query("
                UPDATE {$this->table} 
                SET position_x = :x, position_y = :y 
                WHERE id = :id
            ");
            $this->db->bind(':id', $position['id']);
            $this->db->bind(':x', $position['x']);
            $this->db->bind(':y', $position['y']);
            (bool)$this->db->execute();
        }
        return true;
    }
}


