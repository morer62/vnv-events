<?php

namespace App\Repositories;

class TicketSalesRepository extends BaseRepository
{
    protected string $table = 'ticket_sales';
    protected array $fillable = [
        'id_ticket_type',
        'id_sales_stage',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'quantity',
        'unit_price',
        'total_amount',
        'commission_amount',
        'net_amount',
        'stripe_payment_intent_id',
        'payment_status',
        'ticket_codes',
        'qr_codes',
        'sold_at'
    ];

    public function getByEventTickets(int $eventTicketsId): array
    {
        $this->db->query("
            SELECT 
                ts.*,
                tt.name as ticket_type_name,
                tt.price as original_price,
                tss.name as sales_stage_name,
                tss.discount_percentage
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            WHERE tt.id_venue_event_tickets = :id
            ORDER BY ts.created_at DESC
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchAll();
    }

    public function getByTicketType(int $ticketTypeId): array
    {
        $this->db->query("
            SELECT 
                ts.*,
                tss.name as sales_stage_name,
                tss.discount_percentage
            FROM {$this->table} ts
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            WHERE ts.id_ticket_type = :id
            ORDER BY ts.created_at DESC
        ");
        $this->db->bind(':id', $ticketTypeId);
        return $this->db->fetchAll();
    }

    public function getBySalesStage(int $salesStageId): array
    {
        $this->db->query("
            SELECT 
                ts.*,
                tt.name as ticket_type_name,
                tt.price as original_price
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            WHERE ts.id_sales_stage = :id
            ORDER BY ts.created_at DESC
        ");
        $this->db->bind(':id', $salesStageId);
        return $this->db->fetchAll();
    }

    public function getByBuyerEmail(string $email): array
    {
        if ($this->db === null) {
            $this->db = new Connection();
        }
        
        $this->db->query("
            SELECT 
                ts.*,
                tt.name as ticket_type_name,
                tss.name as sales_stage_name,
                vet.event_type,
                vet.digital_link,
                vet.id_venue_event as venue_event_id
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            INNER JOIN venue_events_tickets vet ON vet.id = tt.id_venue_event_tickets
            WHERE ts.buyer_email = :email
            ORDER BY ts.created_at DESC
        ");
        $this->db->bind(':email', $email);
        
        return $this->db->fetchAll();
    }

    public function getSalesStats(int $eventTicketsId): object
    {
        $this->db->query("
            SELECT 
                COUNT(*) as total_sales,
                SUM(quantity) as total_tickets_sold,
                SUM(total_amount) as total_revenue,
                SUM(commission_amount) as total_commission,
                SUM(net_amount) as total_net,
                AVG(total_amount) as average_sale_amount,
                COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_sales,
                COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_sales,
                COUNT(CASE WHEN payment_status = 'failed' THEN 1 END) as failed_sales,
                COUNT(CASE WHEN payment_status = 'refunded' THEN 1 END) as refunded_sales
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            WHERE tt.id_venue_event_tickets = :id
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchOne() ?: (object)[
            'total_sales' => 0,
            'total_tickets_sold' => 0,
            'total_revenue' => 0,
            'total_commission' => 0,
            'total_net' => 0,
            'average_sale_amount' => 0,
            'paid_sales' => 0,
            'pending_sales' => 0,
            'failed_sales' => 0,
            'refunded_sales' => 0
        ];
    }

    public function getSalesByDateRange(int $eventTicketsId, string $startDate, string $endDate): array
    {
        $this->db->query("
            SELECT 
                DATE(ts.created_at) as sale_date,
                COUNT(*) as sales_count,
                SUM(quantity) as tickets_sold,
                SUM(total_amount) as daily_revenue,
                SUM(commission_amount) as daily_commission,
                SUM(net_amount) as daily_net
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            WHERE tt.id_venue_event_tickets = :id
            AND ts.created_at BETWEEN :start_date AND :end_date
            AND ts.payment_status = 'paid'
            GROUP BY DATE(ts.created_at)
            ORDER BY sale_date ASC
        ");
        $this->db->bind(':id', $eventTicketsId);
        $this->db->bind(':start_date', $startDate);
        $this->db->bind(':end_date', $endDate);
        return $this->db->fetchAll();
    }

    public function getTopSellingTicketTypes(int $eventTicketsId, int $limit = 5): array
    {
        $this->db->query("
            SELECT 
                tt.name as ticket_type_name,
                tt.price as original_price,
                COUNT(ts.id) as sales_count,
                SUM(ts.quantity) as total_tickets_sold,
                SUM(ts.total_amount) as total_revenue,
                AVG(ts.unit_price) as average_sale_price
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            WHERE tt.id_venue_event_tickets = :id
            AND ts.payment_status = 'paid'
            GROUP BY tt.id, tt.name, tt.price
            ORDER BY total_tickets_sold DESC
            LIMIT :limit
        ");
        $this->db->bind(':id', $eventTicketsId);
        $this->db->bind(':limit', $limit);
        return $this->db->fetchAll();
    }

    public function verifyTicketCode(string $ticketCode, int $saleId): ?object
    {
        $this->db->query("
            SELECT 
                ts.*,
                tt.name as ticket_type_name,
                tss.name as sales_stage_name,
                vet.event_type,
                vet.digital_link,
                ve.name as event_name,
                ve.start_date as event_date
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            INNER JOIN venue_events_tickets vet ON vet.id = tt.id_venue_event_tickets
            INNER JOIN venue_events ve ON ve.id = vet.id_venue_event
            WHERE ts.id = :sale_id
            AND JSON_CONTAINS(ts.ticket_codes, :ticket_code)
            AND ts.payment_status = 'paid'
        ");
        $this->db->bind(':sale_id', $saleId);
        $this->db->bind(':ticket_code', json_encode($ticketCode));
        return $this->db->fetchOne();
    }

    public function markTicketAsUsed(string $ticketCode, int $saleId): bool
    {
        // Esta funcionalidad podría implementarse con una tabla adicional
        // para rastrear tickets individuales usados
        return true;
    }

    /**
     * Get sales report with filters and pagination
     */
    public function getSalesReport(int $venueEventId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        if ($this->db === null) {
            $this->db = new Connection();
        }
        
        $whereConditions = [];
        $params = [':venue_event_id' => $venueEventId];

        if (!empty($filters['ticket_type_id'])) {
            $whereConditions[] = "tt.id = :ticket_type_id";
            $params[':ticket_type_id'] = $filters['ticket_type_id'];
        }

        if (!empty($filters['email'])) {
            $whereConditions[] = "ts.buyer_email LIKE :email";
            $params[':email'] = '%' . $filters['email'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $whereConditions[] = "DATE(ts.sold_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereConditions[] = "DATE(ts.sold_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(ts.buyer_name LIKE :search OR ts.buyer_email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($whereConditions) ? 'AND ' . implode(' AND ', $whereConditions) : '';

        $this->db->query("
            SELECT 
                ts.id,
                ts.buyer_name,
                ts.buyer_email,
                ts.buyer_phone,
                ts.quantity,
                ts.unit_price,
                ts.total_amount,
                ts.commission_amount,
                ts.net_amount,
                ts.payment_status,
                ts.sold_at,
                tt.name as ticket_type_name,
                tt.price as original_price,
                tss.name as sales_stage_name,
                tss.discount_percentage
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            INNER JOIN venue_events_tickets vet ON vet.id = tt.id_venue_event_tickets
            WHERE vet.id_venue_event = :venue_event_id
            {$whereClause}
            ORDER BY ts.sold_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        $this->db->bind(':limit', $limit);
        $this->db->bind(':offset', $offset);

        return $this->db->fetchAll();
    }

    /**
     * Get total count of sales for pagination
     */
    public function getSalesReportCount(int $venueEventId, array $filters = []): int
    {
        $whereConditions = [];
        $params = [':venue_event_id' => $venueEventId];

        // Build WHERE conditions based on filters
        if (!empty($filters['ticket_type_id'])) {
            $whereConditions[] = "tt.id = :ticket_type_id";
            $params[':ticket_type_id'] = $filters['ticket_type_id'];
        }

        if (!empty($filters['email'])) {
            $whereConditions[] = "ts.buyer_email LIKE :email";
            $params[':email'] = '%' . $filters['email'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $whereConditions[] = "DATE(ts.sold_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereConditions[] = "DATE(ts.sold_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(ts.buyer_name LIKE :search OR ts.buyer_email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($whereConditions) ? 'AND ' . implode(' AND ', $whereConditions) : '';

        $this->db->query("
            SELECT COUNT(*) as total
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            INNER JOIN venue_events_tickets vet ON vet.id = tt.id_venue_event_tickets
            WHERE vet.id_venue_event = :venue_event_id
            {$whereClause}
        ");

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        $result = $this->db->fetchOne();
        return $result ? (int)$result->total : 0;
    }

    /**
     * Get sales statistics for the report
     */
    public function getSalesReportStats(int $venueEventId, array $filters = []): array
    {
        $whereConditions = [];
        $params = [':venue_event_id' => $venueEventId];

        // Build WHERE conditions based on filters
        if (!empty($filters['ticket_type_id'])) {
            $whereConditions[] = "tt.id = :ticket_type_id";
            $params[':ticket_type_id'] = $filters['ticket_type_id'];
        }

        if (!empty($filters['email'])) {
            $whereConditions[] = "ts.buyer_email LIKE :email";
            $params[':email'] = '%' . $filters['email'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $whereConditions[] = "DATE(ts.sold_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereConditions[] = "DATE(ts.sold_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(ts.buyer_name LIKE :search OR ts.buyer_email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($whereConditions) ? 'AND ' . implode(' AND ', $whereConditions) : '';

        $this->db->query("
            SELECT 
                COUNT(*) as total_orders,
                SUM(ts.quantity) as total_tickets_sold,
                SUM(ts.total_amount) as total_revenue,
                COUNT(DISTINCT ts.buyer_email) as unique_customers
            FROM {$this->table} ts
            INNER JOIN ticket_types tt ON tt.id = ts.id_ticket_type
            INNER JOIN ticket_sales_stages tss ON tss.id = ts.id_sales_stage
            INNER JOIN venue_events_tickets vet ON vet.id = tt.id_venue_event_tickets
            WHERE vet.id_venue_event = :venue_event_id
            {$whereClause}
        ");

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        $result = $this->db->fetchOne();
        return $result ? [
            'total_orders' => (int)$result->total_orders,
            'total_tickets_sold' => (int)$result->total_tickets_sold,
            'total_revenue' => (float)$result->total_revenue,
            'unique_customers' => (int)$result->unique_customers
        ] : [
            'total_orders' => 0,
            'total_tickets_sold' => 0,
            'total_revenue' => 0,
            'unique_customers' => 0
        ];
    }
}


