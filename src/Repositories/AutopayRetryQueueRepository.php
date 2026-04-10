<?php

namespace App\Repositories;

use App\Entity\AutopayRetryQueue;

class AutopayRetryQueueRepository extends BaseRepository
{
    protected string $table = "autopay_retry_queue";
    protected string $entity = AutopayRetryQueue::class;

    public function __construct() {
        $this->db = new Connection();
    }

    /**
     * Add a transaction to retry queue
     */
    public function addToQueue(int $transactionId, int $userId, string $nextRetryDate): int
    {
        $this->add([
            'autopay_transaction_id' => $transactionId,
            'user_id' => $userId,
            'retry_attempt' => 1,
            'next_retry_date' => $nextRetryDate,
            'status' => 'pending'
        ]);
        
        return $this->getLastId();
    }

    /**
     * Get pending retries for today
     */
    public function getPendingRetries(): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE status = 'pending' 
            AND next_retry_date <= CURDATE() 
            ORDER BY created_at ASC
        ");
        
        return $this->db->fetchAll();
    }

    /**
     * Increment retry attempt
     */
    public function incrementRetry(int $queueId, string $nextRetryDate): void
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET retry_attempt = retry_attempt + 1,
                next_retry_date = :next_retry_date
            WHERE id = :id
        ");
        $this->db->bind(':next_retry_date', $nextRetryDate);
        $this->db->bind(':id', $queueId);
        $this->db->execute();
    }

    /**
     * Mark retry as completed or abandoned
     */
    public function updateRetryStatus(int $queueId, string $status): void
    {
        $this->update(['status' => $status], ['id' => $queueId]);
    }

    /**
     * Check if max retries reached
     */
    public function hasReachedMaxRetries(int $queueId): bool
    {
        $retry = $this->getOne(['id' => $queueId]);
        return $retry && $retry->getRetryAttempt() >= $retry->getMaxRetries();
    }
}
