<?php

namespace App\Repositories;

use Exception;

class VenueEventsTicketsRepository extends BaseRepository
{
    protected string $table = 'venue_events_tickets';
    protected array $fillable = [
        'id_venue_event',
        'event_type',
        'digital_link',
        'ticket_sales_enabled',
        'commission_percentage',
        'stripe_fee_percentage',
        'total_commission_percentage'
    ];

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getByVenueEvent(int $venueEventId): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_venue_event = :id LIMIT 1");
        $this->db->bind(':id', $venueEventId);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function createForVenueEvent(int $venueEventId, array $data): int
    {
        $data['id_venue_event'] = $venueEventId;
        $data['total_commission_percentage'] = ($data['commission_percentage'] ?? 5.00) + ($data['stripe_fee_percentage'] ?? 2.90);
        
        return $this->add($data);
    }

    public function enableTicketSales(int $id): bool
    {
        return $this->update(['ticket_sales_enabled' => 1], ['id' => $id]);
    }

    public function disableTicketSales(int $id): bool
    {
        return $this->update(['ticket_sales_enabled' => 0], ['id' => $id]);
    }

    public function getSalesSummary(int $id): object
    {
        $this->db->query("
            SELECT 
                COUNT(ts.id) as total_sales,
                SUM(ts.total_amount) as total_revenue,
                SUM(ts.commission_amount) as total_commission,
                SUM(ts.net_amount) as total_net,
                SUM(ts.quantity) as total_tickets_sold
            FROM ticket_sales ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            WHERE tt.id_venue_event_tickets = :id
            AND ts.payment_status = 'paid'
        ");
        $this->db->bind(':id', $id);
        return $this->db->fetchOne() ?: (object)[
            'total_sales' => 0,
            'total_revenue' => 0,
            'total_commission' => 0,
            'total_net' => 0,
            'total_tickets_sold' => 0
        ];
    }
}
