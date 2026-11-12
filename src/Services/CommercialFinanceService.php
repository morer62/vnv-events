<?php

namespace App\Services;

final class CommercialFinanceService
{
    public const ORIANA_PERCENT = 35.0;
    public const FLORIDA_PERCENT = 30.0;
    public const VNV_RESERVE_PERCENT = 35.0;
    public const ORIGINAL_CONTRIBUTION_POOL_PERCENT = 15.0;

    private const SOURCE_TYPES = ['CORPORATE_FEE', 'SPONSORSHIP_FEE', 'ORIGINAL_CONTRIBUTION'];

    public function calculateCommission(string $sourceType, float $collectedAmount): array
    {
        $sourceType = strtoupper(trim($sourceType));
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported commercial commission source.');
        }
        if (!is_finite($collectedAmount) || $collectedAmount <= 0) {
            throw new \InvalidArgumentException('Collected amount must be greater than zero.');
        }

        $sourceAmount = round($collectedAmount, 2);
        $eligibleAmount = $sourceType === 'ORIGINAL_CONTRIBUTION'
            ? round($sourceAmount * (self::ORIGINAL_CONTRIBUTION_POOL_PERCENT / 100), 2)
            : $sourceAmount;
        $oriana = round($eligibleAmount * (self::ORIANA_PERCENT / 100), 2);
        $florida = round($eligibleAmount * (self::FLORIDA_PERCENT / 100), 2);
        $reserve = round($eligibleAmount - $oriana - $florida, 2);

        return [
            'source_type' => $sourceType,
            'source_amount' => $sourceAmount,
            'eligible_amount' => $eligibleAmount,
            'oriana_amount' => $oriana,
            'florida_amount' => $florida,
            'vnv_reserve_amount' => $reserve,
            'oriana_percent' => self::ORIANA_PERCENT,
            'florida_percent' => self::FLORIDA_PERCENT,
            'vnv_reserve_percent' => self::VNV_RESERVE_PERCENT,
        ];
    }

    public function validateSupportGrant(float $consultationHours, float $foodStaffPercent, float $decorPercent, string $scope): array
    {
        if (!is_finite($consultationHours) || $consultationHours < 0 || $consultationHours > 1000) {
            throw new \InvalidArgumentException('Consultation hours must be between 0 and 1,000.');
        }
        if (!is_finite($foodStaffPercent) || $foodStaffPercent < 0 || $foodStaffPercent > 60) {
            throw new \InvalidArgumentException('Selected food and staffing support cannot exceed 60%.');
        }
        if (!is_finite($decorPercent) || $decorPercent < 0 || $decorPercent > 80) {
            throw new \InvalidArgumentException('Selected decor support cannot exceed 80%.');
        }
        $scope = trim($scope);
        if ($scope === '') {
            throw new \InvalidArgumentException('An approved support scope is required.');
        }

        return [
            'consultation_hours' => round($consultationHours, 2),
            'discount_food_staff_percent' => round($foodStaffPercent, 2),
            'discount_decor_percent' => round($decorPercent, 2),
            'approved_scope' => mb_substr($scope, 0, 5000),
        ];
    }
}
