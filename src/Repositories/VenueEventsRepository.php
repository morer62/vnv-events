<?php

namespace App\Repositories;

class VenueEventsRepository extends BaseRepository
{
    public function __construct() {
        $this->table = "venue_events";
        $this->db = new Connection();
    }

    public function getActiveWithTicketConfig(int $limit = 60): array
    {
        $limit = max(1, min($limit, 200));

        $this->db->query("
            SELECT
                ve.*,
                v.name AS venue_name,
                v.address AS venue_location,
                vet.id AS tickets_config_id,
                vet.ticket_sales_enabled,
                vet.event_type,
                vet.digital_link,
                (
                    SELECT COUNT(*)
                    FROM ticket_types tt
                    WHERE tt.id_venue_event_tickets = vet.id
                      AND COALESCE(tt.is_active, 1) = 1
                ) AS ticket_type_count
            FROM venue_events ve
            LEFT JOIN venues v ON v.id = ve.venue_id
            LEFT JOIN venue_events_tickets vet ON vet.id_venue_event = ve.id
            WHERE (ve.end_date IS NULL OR ve.end_date >= NOW())
            ORDER BY ve.start_date ASC, ve.id DESC
            LIMIT {$limit}
        ");

        return $this->db->fetchAll();
    }

    public function getActiveByVenueWithTicketConfig(int $venueId): array
    {
        $this->db->query("
            SELECT
                ve.*,
                vet.id AS tickets_config_id,
                vet.ticket_sales_enabled,
                vet.event_type,
                vet.digital_link,
                (
                    SELECT COUNT(*)
                    FROM ticket_types tt
                    WHERE tt.id_venue_event_tickets = vet.id
                      AND COALESCE(tt.is_active, 1) = 1
                ) AS ticket_type_count
            FROM venue_events ve
            LEFT JOIN venue_events_tickets vet ON vet.id_venue_event = ve.id
            WHERE ve.venue_id = :venue_id
            ORDER BY
                CASE WHEN ve.end_date IS NULL OR ve.end_date >= NOW() THEN 0 ELSE 1 END,
                ve.start_date ASC,
                ve.id DESC
        ");
        $this->db->bind(':venue_id', $venueId);

        return $this->db->fetchAll();
    }
}
