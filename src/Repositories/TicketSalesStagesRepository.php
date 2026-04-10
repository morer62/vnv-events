<?php

namespace App\Repositories;

class TicketSalesStagesRepository extends BaseRepository
{
    protected string $table = 'ticket_sales_stages';
    protected array $fillable = [
        'id_venue_event_tickets',
        'name',
        'description',
        'start_date',
        'end_date',
        'discount_percentage',
        'is_active',
        'sort_order'
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
            ORDER BY sort_order ASC, start_date ASC
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
            ORDER BY sort_order ASC, start_date ASC
        ");
        $this->db->bind(':id', $eventTicketsId);
        return $this->db->fetchAll();
    }

    public function validateStagePeriod(int $eventTicketsId, string $startDate, string $endDate, ?int $excludeId = null): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_venue_event_tickets = :id 
            AND is_active = 1
            AND (
                (start_date <= :start_date AND end_date >= :start_date) OR
                (start_date <= :end_date AND end_date >= :end_date) OR
                (start_date >= :start_date AND end_date <= :end_date)
            )
        ");
        
        if ($excludeId) {
            $this->db->query("
                SELECT * FROM {$this->table} 
                WHERE id_venue_event_tickets = :id 
                AND is_active = 1
                AND id != :exclude_id
                AND (
                    (start_date <= :start_date AND end_date >= :start_date) OR
                    (start_date <= :end_date AND end_date >= :end_date) OR
                    (start_date >= :start_date AND end_date <= :end_date)
                )
            ");
            $this->db->bind(':exclude_id', $excludeId);
        }
        
        $this->db->bind(':id', $eventTicketsId);
        $this->db->bind(':start_date', $startDate);
        $this->db->bind(':end_date', $endDate);
        
        $conflictingStages = $this->db->fetchAll();
        
        if (!empty($conflictingStages)) {
            return [
                'valid' => false,
                'message' => 'There is already an active sales stage in this time period',
                'conflicting_stages' => $conflictingStages
            ];
        }
        
        return ['valid' => true];
    }

    public function validateStageOrder(int $eventTicketsId, string $startDate, ?int $excludeId = null): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_venue_event_tickets = :id 
            AND is_active = 1
            AND end_date > :start_date
            ORDER BY sort_order ASC
        ");
        
        if ($excludeId) {
            $this->db->query("
                SELECT * FROM {$this->table} 
                WHERE id_venue_event_tickets = :id 
                AND is_active = 1
                AND id != :exclude_id
                AND end_date > :start_date
                ORDER BY sort_order ASC
            ");
            $this->db->bind(':exclude_id', $excludeId);
        }
        
        $this->db->bind(':id', $eventTicketsId);
        $this->db->bind(':start_date', $startDate);
        
        $laterStages = $this->db->fetchAll();
        
        if (!empty($laterStages)) {
            return [
                'valid' => false,
                'message' => 'Cannot create a stage that starts before an existing stage ends',
                'conflicting_stages' => $laterStages
            ];
        }
        
        return ['valid' => true];
    }

    public function getCurrentStage(int $eventTicketsId): ?object
    {
        $now = date('Y-m-d H:i:s');
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_venue_event_tickets = :id 
            AND is_active = 1
            AND start_date <= :now
            AND end_date >= :now
            ORDER BY sort_order ASC
            LIMIT 1
        ");
        $this->db->bind(':id', $eventTicketsId);
        $this->db->bind(':now', $now);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function createStage(int $eventTicketsId, array $data): array
    {
        $data['id_venue_event_tickets'] = $eventTicketsId;
        
        // Validate period overlap
        $periodValidation = $this->validateStagePeriod(
            $eventTicketsId, 
            $data['start_date'], 
            $data['end_date']
        );
        
        if (!$periodValidation['valid']) {
            return [
                'success' => false,
                'message' => $periodValidation['message'],
                'conflicting_stages' => $periodValidation['conflicting_stages'] ?? []
            ];
        }
        
        // Validate stage order
        $orderValidation = $this->validateStageOrder(
            $eventTicketsId, 
            $data['start_date']
        );
        
        if (!$orderValidation['valid']) {
            return [
                'success' => false,
                'message' => $orderValidation['message'],
                'conflicting_stages' => $orderValidation['conflicting_stages'] ?? []
            ];
        }
        
        if (!isset($data['sort_order'])) {
            $this->db->query("
                SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order 
                FROM {$this->table} 
                WHERE id_venue_event_tickets = :id
            ");
            $this->db->bind(':id', $eventTicketsId);
            $result = $this->db->fetchOne();
            $data['sort_order'] = $result->next_order;
        }
        
        $stageId = $this->add($data);
        
        return [
            'success' => true,
            'stage_id' => $stageId
        ];
    }

    public function updateStage(int $id, array $data): bool
    {
        return $this->update($data, ['id' => $id]);
    }

    public function activateStage(int $id): bool
    {
        return $this->update(['is_active' => 1], ['id' => $id]);
    }

    public function deactivateStage(int $id): bool
    {
        return $this->update(['is_active' => 0], ['id' => $id]);
    }

    public function reorderStages(int $eventTicketsId, array $stageIds): bool
    {
        $this->db->beginTransaction();
        
        try {
            foreach ($stageIds as $order => $stageId) {
                $this->db->query("
                    UPDATE {$this->table} 
                    SET sort_order = :order 
                    WHERE id = :id AND id_venue_event_tickets = :event_id
                ");
                $this->db->bind(':order', $order + 1);
                $this->db->bind(':id', $stageId);
                $this->db->bind(':event_id', $eventTicketsId);
                $this->db->execute();
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
}
