<?php

namespace App\Repositories;

class TicketInventoryRepository extends BaseRepository
{
    protected string $table = 'ticket_inventory';
    protected array $fillable = [
        'id_ticket_type',
        'id_sales_stage',
        'total_quantity',
        'sold_quantity',
        'available_quantity'
    ];

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getByTicketType(int $ticketTypeId): array
    {
        $this->db->query("
            SELECT 
                ti.*,
                tss.name as stage_name,
                tss.start_date,
                tss.end_date,
                tss.discount_percentage
            FROM {$this->table} ti
            INNER JOIN ticket_sales_stages tss ON tss.id = ti.id_sales_stage
            WHERE ti.id_ticket_type = :id
            ORDER BY tss.sort_order ASC
        ");
        $this->db->bind(':id', $ticketTypeId);
        return $this->db->fetchAll();
    }

    public function getBySalesStage(int $salesStageId): array
    {
        $this->db->query("
            SELECT 
                ti.*,
                tt.name as ticket_type_name,
                tt.price,
                tt.description
            FROM {$this->table} ti
            INNER JOIN ticket_types tt ON tt.id = ti.id_ticket_type
            WHERE ti.id_sales_stage = :id
            ORDER BY tt.price ASC
        ");
        $this->db->bind(':id', $salesStageId);
        return $this->db->fetchAll();
    }

    public function getInventoryMatrix(int $eventTicketsId): array
    {
        $this->db->query("
            SELECT 
                tt.id as ticket_type_id,
                tt.name as ticket_type_name,
                tt.price,
                tss.id as stage_id,
                tss.name as stage_name,
                tss.start_date,
                tss.end_date,
                tss.discount_percentage,
                COALESCE(ti.total_quantity, 0) as total_quantity,
                COALESCE(ti.sold_quantity, 0) as sold_quantity,
                COALESCE(ti.available_quantity, 0) as available_quantity
            FROM ticket_types tt
            CROSS JOIN ticket_sales_stages tss
            LEFT JOIN {$this->table} ti ON ti.id_ticket_type = tt.id AND ti.id_sales_stage = tss.id
            WHERE tt.id_venue_event_tickets = :id
            AND tss.id_venue_event_tickets = :id
            ORDER BY tt.price ASC, tss.sort_order ASC
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchAll();
    }

    public function createInventory(int $ticketTypeId, int $salesStageId, int $quantity): int
    {
        $data = [
            'id_ticket_type' => $ticketTypeId,
            'id_sales_stage' => $salesStageId,
            'total_quantity' => $quantity,
            'sold_quantity' => 0,
            'available_quantity' => $quantity
        ];
        
        return $this->add($data);
    }

    public function updateInventory(int $ticketTypeId, int $salesStageId, int $quantity): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET total_quantity = :quantity,
                available_quantity = :quantity - sold_quantity
            WHERE id_ticket_type = :ticket_type_id 
            AND id_sales_stage = :sales_stage_id
        ");
        $this->db->bind(':quantity', $quantity);
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $this->db->bind(':sales_stage_id', $salesStageId);
        $this->db->execute();
        
        return $this->db->rowCount() > 0;
    }

    public function checkAvailability(int $ticketTypeId, int $salesStageId, int $requestedQuantity): bool
    {
        $this->db->query("
            SELECT available_quantity 
            FROM {$this->table} 
            WHERE id_ticket_type = :ticket_type_id 
            AND id_sales_stage = :sales_stage_id
        ");
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $this->db->bind(':sales_stage_id', $salesStageId);
        $result = $this->db->fetchOne();
        
        return $result && $result->available_quantity >= $requestedQuantity;
    }

    public function getAvailableQuantity(int $ticketTypeId): int
    {
        $this->db->query("
            SELECT SUM(available_quantity) as total_available
            FROM {$this->table} 
            WHERE id_ticket_type = :ticket_type_id
        ");
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $result = $this->db->fetchOne();
        
        return $result ? (int)$result->total_available : 0;
    }

    public function initializeInventoryForTicketType(int $ticketTypeId, int $defaultQuantity = 100): bool
    {
        // Verificar si ya existe inventario
        $this->db->query("SELECT COUNT(*) as count FROM {$this->table} WHERE id_ticket_type = :ticket_type_id");
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $result = $this->db->fetchOne();
        
        if ($result && $result->count > 0) {
            return true; // Ya existe inventario
        }
        
        // Crear inventario por defecto para la etapa 1 (si existe)
        $this->db->query("
            INSERT INTO {$this->table} 
            (id_ticket_type, id_sales_stage, total_quantity, sold_quantity, available_quantity) 
            VALUES (:ticket_type_id, 1, :quantity, 0, :quantity)
        ");
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $this->db->bind(':quantity', $defaultQuantity);
        
        return $this->db->execute() !== false;
    }

    public function reserveTickets(int $ticketTypeId, int $salesStageId, int $quantity): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET sold_quantity = sold_quantity + :quantity,
                available_quantity = available_quantity - :quantity
            WHERE id_ticket_type = :ticket_type_id 
            AND id_sales_stage = :sales_stage_id
            AND available_quantity >= :quantity
        ");
        $this->db->bind(':quantity', $quantity);
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $this->db->bind(':sales_stage_id', $salesStageId);
        $this->db->execute();
        
        return $this->db->rowCount() > 0;
    }

    public function releaseTickets(int $ticketTypeId, int $salesStageId, int $quantity): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET sold_quantity = GREATEST(0, sold_quantity - :quantity),
                available_quantity = available_quantity + :quantity
            WHERE id_ticket_type = :ticket_type_id 
            AND id_sales_stage = :sales_stage_id
        ");
        $this->db->bind(':quantity', $quantity);
        $this->db->bind(':ticket_type_id', $ticketTypeId);
        $this->db->bind(':sales_stage_id', $salesStageId);
        $this->db->execute();
        
        return $this->db->rowCount() > 0;
    }
}
