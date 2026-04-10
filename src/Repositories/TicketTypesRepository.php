<?php

namespace App\Repositories;

class TicketTypesRepository extends BaseRepository
{
    protected string $table = 'ticket_types';
    protected array $fillable = [
        'id_venue_event_tickets',
        'name',
        'description',
        'price',
        'is_active'
    ];

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getByEventTickets(int $eventTicketsId): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_venue_event_tickets = :id 
            ORDER BY price ASC
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchAll();
    }

    public function getActiveByEventTickets(int $eventTicketsId): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_venue_event_tickets = :id 
            AND is_active = 1
            ORDER BY price ASC
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchAll();
    }

    public function createType(int $eventTicketsId, array $data): int
    {
        $data['id_venue_event_tickets'] = $eventTicketsId;
        return $this->add($data);
    }

    public function updateType(int $id, array $data): bool
    {
        return $this->update($data, ['id' => $id]);
    }

    public function activateType(int $id): bool
    {
        return $this->update(['is_active' => 1], ['id' => $id]);
    }

    public function deactivateType(int $id): bool
    {
        return $this->update(['is_active' => 0], ['id' => $id]);
    }

    public function getWithInventory(int $eventTicketsId): array
    {
        $this->db->query("
            SELECT 
                tt.*,
                COALESCE(SUM(ti.total_quantity), 0) as total_inventory,
                COALESCE(SUM(ti.sold_quantity), 0) as total_sold,
                COALESCE(SUM(ti.available_quantity), 0) as total_available
            FROM {$this->table} tt
            LEFT JOIN ticket_inventory ti ON ti.id_ticket_type = tt.id
            WHERE tt.id_venue_event_tickets = :id
            GROUP BY tt.id
            ORDER BY tt.price ASC
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchAll();
    }

    /**
     * Get ticket types by venue event ID
     */
    public function getByEventId(int $venueEventId): array
    {
        $this->db->query("
            SELECT tt.*
            FROM {$this->table} tt
            INNER JOIN venue_events_tickets vet ON vet.id = tt.id_venue_event_tickets
            WHERE vet.id_venue_event = :venue_event_id
            ORDER BY tt.price ASC
        ");
        $this->db->bind(':venue_event_id', $venueEventId);
        return $this->db->fetchAll();
    }
}
