<?php

namespace App\Repositories;

class TicketCommissionsRepository extends BaseRepository
{
    protected string $table = 'ticket_commissions';
    protected array $fillable = [
        'id_ticket_sale',
        'event_name',
        'venue_name',
        'buyer_email',
        'total_amount',
        'commission_amount',
        'commission_percentage',
        'stripe_transfer_id',
        'transfer_status'
    ];

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function createCommission(array $data): int
    {
        return $this->add($data);
    }

    public function updateTransferStatus(int $id, string $transferId, string $status): bool
    {
        return $this->update([
            'stripe_transfer_id' => $transferId,
            'transfer_status' => $status
        ], ['id' => $id]);
    }

    public function getCommissionsByDateRange(string $startDate, string $endDate): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE created_at BETWEEN :start_date AND :end_date
            ORDER BY created_at DESC
        ");
        $this->db->bind(':start_date', $startDate);
        $this->db->bind(':end_date', $endDate);
        return $this->db->fetchAll();
    }

    public function getTotalCommissions(): ?object
    {
        $this->db->query("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(commission_amount) as total_commission,
                AVG(commission_percentage) as avg_commission_percentage
            FROM {$this->table}
            WHERE transfer_status = 'completed'
        ");
        return $this->db->fetchOne();
    }
}
