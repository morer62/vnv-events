<?php

namespace App\Repositories;

class AffiliateCommissionPaymentsRepository extends BaseRepository
{
    protected array $fields = [
        'referrer_id',
        'commission_ids',
        'total_amount',
        'commission_count',
        'payment_method',
        'payment_proof_url',
        'stripe_transfer_id',
        'payout_batch_id',
        'status',
        'paid_at',
        'notes',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "affiliate_commission_payments";
        $this->db = new Connection();
    }

    /**
     * Crear registro de pago de comisiones
     */
    public function createPayment(array $data): bool
    {
        try {
            $result = $this->add($data);
            return $result !== false;
        } catch (\Exception $e) {
            error_log("Error creating commission payment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener pagos por usuario
     */
    public function getByReferrer(int $referrerId): array
    {
        return $this->getAllBy(['referrer_id' => $referrerId]);
    }

    /**
     * Obtener todos los pagos (para admin)
     */
    public function getAllPayments(): array
    {
        $sql = "
            SELECT acp.*, u.name as referrer_name, u.email as referrer_email
            FROM {$this->table} acp
            JOIN users u ON acp.referrer_id = u.id
            ORDER BY acp.created_at DESC
        ";
        
        $this->db->query($sql);
        return $this->db->fetchAll();
    }
}
