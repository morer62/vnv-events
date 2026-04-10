<?php

namespace App\Entity;

class AutopayTransaction
{
    private ?int $id = null;
    private int $userId;
    private int $autopaySettingId;
    private float $amount;
    private string $planType;
    private string $status; // 'success', 'failed', 'pending'
    private ?string $stripeChargeId = null;
    private ?string $errorMessage = null;
    private string $previousDueDate;
    private ?string $newDueDate = null;
    private ?string $processedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getAutopaySettingId(): int
    {
        return $this->autopaySettingId;
    }

    public function setAutopaySettingId(int $autopaySettingId): void
    {
        $this->autopaySettingId = $autopaySettingId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function getPlanType(): string
    {
        return $this->planType;
    }

    public function setPlanType(string $planType): void
    {
        $this->planType = $planType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStripeChargeId(): ?string
    {
        return $this->stripeChargeId;
    }

    public function setStripeChargeId(?string $stripeChargeId): void
    {
        $this->stripeChargeId = $stripeChargeId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getPreviousDueDate(): string
    {
        return $this->previousDueDate;
    }

    public function setPreviousDueDate(string $previousDueDate): void
    {
        $this->previousDueDate = $previousDueDate;
    }

    public function getNewDueDate(): ?string
    {
        return $this->newDueDate;
    }

    public function setNewDueDate(?string $newDueDate): void
    {
        $this->newDueDate = $newDueDate;
    }

    public function getProcessedAt(): ?string
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?string $processedAt): void
    {
        $this->processedAt = $processedAt;
    }
}
