<?php

namespace App\Repositories;

use App\Entity\AutopayTransaction;

class AutopayTransactionRepository extends BaseRepository
{
    protected string $table = "autopay_transactions";
    protected string $entity = AutopayTransaction::class;

    public function __construct() {
        $this->db = new Connection();
    }

    /**
     * Log an autopay transaction attempt
     */
    public function logTransaction(array $data): int
    {
        $this->add($data);
        return $this->getLastId();
    }

    /**
     * Get transaction history for a user
     */
    public function getUserTransactions(int $userId, int $limit = 10): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE user_id = :user_id 
            ORDER BY processed_at DESC 
            LIMIT :limit
        ");
        $this->db->bind(':user_id', $userId);
        $this->db->bind(':limit', $limit);
        
        return $this->db->fetchAll();
    }

    /**
     * Get successful transactions count for a user
     */
    public function getSuccessfulCount(int $userId): int
    {
        $this->db->query("
            SELECT COUNT(*) as count 
            FROM {$this->table} 
            WHERE user_id = :user_id AND status = 'success'
        ");
        $this->db->bind(':user_id', $userId);
        $result = $this->db->fetch();
        
        return $result->count ?? 0;
    }

    /**
     * Update transaction status
     */
    public function updateStatus(int $transactionId, string $status, ?string $stripeChargeId = null, ?string $errorMessage = null): void
    {
        $data = ['status' => $status];
        
        if ($stripeChargeId) {
            $data['stripe_charge_id'] = $stripeChargeId;
        }
        
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }
        
        $this->update($data, ['id' => $transactionId]);
    }
}
