<?php

namespace App\Services;

use App\Repositories\StoreCouponCustomersRepository;
use App\Repositories\StoreCouponRedemptionsRepository;
use App\Repositories\StoreCouponsRepository;
use App\Repositories\StoreOrdersRepository;

class StoreCouponService
{
    private StoreCouponsRepository $couponsRepo;
    private StoreCouponCustomersRepository $couponCustomersRepo;
    private StoreCouponRedemptionsRepository $redemptionsRepo;
    private StoreOrdersRepository $ordersRepo;

    public function __construct()
    {
        $this->couponsRepo = new StoreCouponsRepository();
        $this->couponCustomersRepo = new StoreCouponCustomersRepository();
        $this->redemptionsRepo = new StoreCouponRedemptionsRepository();
        $this->ordersRepo = new StoreOrdersRepository();
    }

    public function normalizeCode(string $code): string
    {
        return $this->couponsRepo->normalizeCode($code);
    }

    public function validateAndCalculate(
        int $ownerId,
        string $code,
        float $subtotal,
        string $pricingMode,
        ?int $userId,
        ?string $email
    ): array {
        $cleanCode = $this->normalizeCode($code);
        if ($cleanCode === '') {
            return ['ok' => false, 'message' => 'Coupon code is required.'];
        }

        $pricingMode = strtoupper(trim((string)$pricingMode));
        if (!in_array($pricingMode, [
            StoreOrdersRepository::PRICING_PAYG,
            StoreOrdersRepository::PRICING_SUBSCRIPTION
        ], true)) {
            return ['ok' => false, 'message' => 'Coupons are not available for this order type.'];
        }

        $coupon = $this->couponsRepo->getByOwnerAndCode($ownerId, $cleanCode);
        if (!$coupon) {
            return ['ok' => false, 'message' => 'Coupon not found.'];
        }

        /**
         * IMPORTANTE:
         * Se elimina temporalmente la restricción por purchase_mode
         * para permitir usar el mismo cupón tanto en PAYG como en SUBSCRIPTION.
         */
        /*
        $purchaseMode = strtoupper((string)($coupon->purchase_mode ?? StoreCouponsRepository::PURCHASE_MODE_PAYG));

        if ($purchaseMode === StoreCouponsRepository::PURCHASE_MODE_PAYG
            && $pricingMode === StoreOrdersRepository::PRICING_SUBSCRIPTION) {
            return ['ok' => false, 'message' => 'This coupon is for one-time purchases only.'];
        }

        if ($purchaseMode === StoreCouponsRepository::PURCHASE_MODE_SUBSCRIPTION
            && $pricingMode === StoreOrdersRepository::PRICING_PAYG) {
            return ['ok' => false, 'message' => 'This coupon is for subscriptions only.'];
        }
        */

        if (strtoupper((string)$coupon->status) !== StoreCouponsRepository::STATUS_ACTIVE) {
            return ['ok' => false, 'message' => 'Coupon is inactive.'];
        }

        $now = date('Y-m-d H:i:s');
        if (!empty($coupon->starts_at) && (string)$coupon->starts_at > $now) {
            return ['ok' => false, 'message' => 'Coupon is not active yet.'];
        }
        if (!empty($coupon->expires_at) && (string)$coupon->expires_at < $now) {
            return ['ok' => false, 'message' => 'Coupon expired.'];
        }

        $subtotal = round(max(0, $subtotal), 2);
        $minOrderTotal = (float)($coupon->min_order_total ?? 0);
        if ($subtotal < $minOrderTotal) {
            return ['ok' => false, 'message' => 'Minimum order total for this coupon is $' . number_format($minOrderTotal, 2) . '.'];
        }

        /**
         * Si el cupón es customer-specific, mantenemos esa validación.
         * Eso protege cupones privados/asignados.
         */
        $scope = strtoupper((string)($coupon->scope ?? StoreCouponsRepository::SCOPE_GLOBAL));
        if ($scope === StoreCouponsRepository::SCOPE_CUSTOMER) {
            $allowed = $this->couponCustomersRepo->isAllowedForCoupon(
                (int)$coupon->id,
                (int)$userId,
                (string)$email
            );
            if (!$allowed) {
                return ['ok' => false, 'message' => 'Coupon is not assigned to this customer.'];
            }
        }

        /**
         * Mantengo max_total_uses global para evitar que un cupón realmente agotado
         * se siga usando infinitamente.
         */
        $maxTotalUses = (int)($coupon->max_total_uses ?? 0);
        $totalUses = (int)($coupon->total_uses ?? 0);
        if ($maxTotalUses > 0 && $totalUses >= $maxTotalUses) {
            return ['ok' => false, 'message' => 'Coupon usage limit reached.'];
        }

        /**
         * IMPORTANTE:
         * Se elimina temporalmente la validación por cliente / usos previos.
         * Así no bloquea ni por email, ni por userId, ni por recompra.
         */
        /*
        $maxPerCustomer = (int)($coupon->max_uses_per_customer ?? 0);
        if ($maxPerCustomer > 0) {
            $usedByCustomer = $this->redemptionsRepo->countByCouponAndCustomer(
                (int)$coupon->id,
                (int)$userId,
                (string)$email
            );
            if ($usedByCustomer >= $maxPerCustomer) {
                return ['ok' => false, 'message' => 'You already used this coupon.'];
            }
        }
        */

        $discountValue = (float)($coupon->discount_value ?? 0);
        $discountType = strtoupper((string)($coupon->discount_type ?? StoreCouponsRepository::TYPE_PERCENT));

        if ($discountType === StoreCouponsRepository::TYPE_PERCENT) {
            $discount = round(($subtotal * $discountValue) / 100, 2);
        } else {
            $discount = round($discountValue, 2);
        }

        $discount = round(min($discount, $subtotal), 2);
        $total = round($subtotal - $discount, 2);

        if ($discount <= 0) {
            return ['ok' => false, 'message' => 'Coupon has no applicable discount for this order.'];
        }

        return [
            'ok' => true,
            'coupon' => $coupon,
            'discount' => $discount,
            'total' => $total,
            'code' => $cleanCode
        ];
    }
}