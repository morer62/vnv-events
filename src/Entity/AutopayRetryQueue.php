<?php

namespace App\Entity;

class AutopayRetryQueue
{
    private ?int $id = null;
    private int $autopayTransactionId;
    private int $userId;
    private int $retryAttempt = 1;
    private int $maxRetries = 3;
    private string $nextRetryDate;
    private string $status = 'pending'; // 'pending', 'completed', 'abandoned'
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getAutopayTransactionId(): int
    {
        return $this->autopayTransactionId;
    }

    public function setAutopayTransactionId(int $autopayTransactionId): void
    {
        $this->autopayTransactionId = $autopayTransactionId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getRetryAttempt(): int
    {
        return $this->retryAttempt;
    }

    public function setRetryAttempt(int $retryAttempt): void
    {
        $this->retryAttempt = $retryAttempt;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    public function getNextRetryDate(): string
    {
        return $this->nextRetryDate;
    }

    public function setNextRetryDate(string $nextRetryDate): void
    {
        $this->nextRetryDate = $nextRetryDate;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?string $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?string $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
