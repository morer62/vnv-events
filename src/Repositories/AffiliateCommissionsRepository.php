<?php

namespace App\Repositories;

class AffiliateCommissionsRepository extends BaseRepository
{
    protected array $fields = [
        'referrer_id',
        'referred_id', 
        'referral_id',
        'transaction_type',
        'transaction_id',
        'order_id',
        'payment_id',
        'gross_amount',
        'commission_rate',
        'commission_amount',
        'currency',
        'status',
        'payment_method',
        'paid_at',
        'payout_batch_id',
        'notes',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "affiliate_commissions";
        $this->db = new Connection();
    }

    /**
     * Obtener comisiones pendientes por usuario (referrer)
     */
    public function getPendingByReferrer(int $referrerId): array
    {
        $sql = "
            SELECT ac.*, u.name as referred_name, u.email as referred_email
            FROM {$this->table} ac
            JOIN users u ON ac.referred_id = u.id
            WHERE ac.referrer_id = :referrer_id 
            AND ac.status IN ('pending', 'approved')
            ORDER BY ac.created_at ASC
        ";
        
        $this->db->query($sql);
        $this->db->bind(':referrer_id', $referrerId);
        return $this->db->fetchAll();
    }

    /**
     * Obtener todas las comisiones por usuario (para admin)
     */
    public function getAllByReferrer(int $referrerId): array
    {
        $sql = "
            SELECT ac.*, u.name as referred_name, u.email as referred_email
            FROM {$this->table} ac
            JOIN users u ON ac.referred_id = u.id
            WHERE ac.referrer_id = :referrer_id 
            ORDER BY ac.created_at DESC
        ";
        
        $this->db->query($sql);
        $this->db->bind(':referrer_id', $referrerId);
        return $this->db->fetchAll();
    }

    /**
     * Obtener comisiones agrupadas por usuario para el panel de admin
     */
    public function getGroupedPendingCommissions(): array
    {
        $sql = "
            SELECT 
                ac.referrer_id,
                u.name as referrer_name,
                u.email as referrer_email,
                COUNT(*) as commission_count,
                SUM(ac.commission_amount) as total_amount,
                MIN(ac.created_at) as oldest_commission,
                MAX(ac.created_at) as newest_commission
            FROM {$this->table} ac
            JOIN users u ON ac.referrer_id = u.id
            WHERE ac.status IN ('pending', 'approved')
            GROUP BY ac.referrer_id, u.name, u.email
            HAVING total_amount > 0
            ORDER BY total_amount DESC
        ";
        
        $this->db->query($sql);
        return $this->db->fetchAll();
    }

    /**
     * Marcar comisiones como pagadas
     */
    public function markAsPaid(array $commissionIds, string $paymentMethod = 'manual', ?string $payoutBatchId = null): bool
    {
        if (empty($commissionIds)) {
            return false;
        }

        $placeholders = str_repeat('?,', count($commissionIds) - 1) . '?';
        
        $sql = "
            UPDATE {$this->table} 
            SET status = 'paid',
                payment_method = ?,
                paid_at = NOW(),
                payout_batch_id = ?,
                updated_at = NOW()
            WHERE id IN ($placeholders)
        ";
        
        $this->db->query($sql);
        
        // Bind parameters
        $this->db->bind(1, $paymentMethod);
        $this->db->bind(2, $payoutBatchId);
        
        foreach ($commissionIds as $index => $id) {
            $this->db->bind($index + 3, $id);
        }
        
        $result = $this->db->execute();
        return $result !== false;
    }

    /**
     * Obtener comisiones por IDs específicos
     */
    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        $sql = "
            SELECT ac.*, u.name as referred_name, u.email as referred_email
            FROM {$this->table} ac
            JOIN users u ON ac.referred_id = u.id
            WHERE ac.id IN ($placeholders)
            ORDER BY ac.created_at ASC
        ";
        
        $this->db->query($sql);
        foreach ($ids as $index => $id) {
            $this->db->bind($index + 1, $id);
        }
        
        return $this->db->fetchAll();
    }

    /**
     * Crear comisión
     */
    public function createCommission(array $data): bool
    {
        return $this->add($data);
    }
}
