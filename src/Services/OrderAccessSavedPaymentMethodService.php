<?php

namespace App\Services;

use App\Services\Payment\AbstractPaymentProvider;

class OrderAccessSavedPaymentMethodService
{
    private ClientPaymentMethodService $methods;

    public function __construct()
    {
        $this->methods = new ClientPaymentMethodService();
    }

    public function viewDataForOrder(object $order, int $businessId, string $providerType, bool $allowRewards = true): array
    {
        $session = LoginService::getSession();
        $clientId = (int)($order->id_client ?? 0);
        $supportsFuture = in_array($providerType, ['stripe', 'square'], true);
        $canUseSaved = $session && (int)$session->getLevel() === 5 && (int)$session->getId() === $clientId && $supportsFuture;
        $canUseRewards = $allowRewards && $session && (int)$session->getLevel() === 5 && (int)$session->getId() === $clientId;
        $rewards = $canUseRewards ? (new LoyaltyRewardsService())->balance($businessId, $clientId, 'vnvevents') : null;

        return [
            'can_use_saved_payment_methods' => $canUseSaved,
            'saved_payment_methods' => $canUseSaved ? $this->methods->listClientSavedPaymentMethodsForProvider($businessId, $clientId, $providerType) : [],
            'supports_future_payment_methods' => $supportsFuture,
            'payment_consent_text' => 'I authorize this business to charge my saved payment method for balances, approved orders, recurring charges, tips or pending payments related to my services or purchases.',
            'payment_consent_version' => ClientPaymentMethodService::CONSENT_VERSION,
            'loyalty_can_redeem' => $canUseRewards && (float)($rewards['available_points'] ?? 0) > 0,
            'loyalty_balance' => $rewards,
        ];
    }

    public function reserveRewardsForPost(object $order, int $businessId, float $maximumDiscount, string $targetType): array
    {
        $requested = max(0, (float)($_POST['loyalty_points'] ?? 0));
        $result = ['token' => null, 'points' => 0.0, 'discount' => 0.0, 'amount_due' => round($maximumDiscount, 2)];
        if ($requested <= 0) return $result;

        $session = LoginService::getSession();
        $clientId = (int)($order->id_client ?? 0);
        if (!$session || (int)$session->getLevel() !== 5 || (int)$session->getId() !== $clientId) {
            throw new \RuntimeException('Please log in as the order client to use rewards.');
        }

        $reservation = (new LoyaltyRewardsService())->reserve($businessId, $clientId, $requested, $maximumDiscount, $targetType, (int)$order->id, 'vnvevents');
        return [
            'token' => (string)$reservation['token'],
            'points' => (float)$reservation['points'],
            'discount' => (float)$reservation['discount'],
            'amount_due' => max(0, round($maximumDiscount - (float)$reservation['discount'], 2)),
        ];
    }

    public function releaseRewards(?string $token): void
    {
        if ($token) (new LoyaltyRewardsService())->releaseReservation($token);
    }

    public function commitRewards(?string $token, int $actorId): void
    {
        if ($token) (new LoyaltyRewardsService())->commitReservation($token, $actorId);
    }

    public function chargeFromPost(AbstractPaymentProvider $provider, object $activeProvider, object $order, int $businessId, float $amount, array $metadata): array
    {
        $paymentType = strtolower((string)($metadata['payment_type'] ?? 'payment'));
        $source = strtolower((string)($metadata['source'] ?? ''));
        $allowRewards = empty($metadata['suborder_id'])
            && !str_starts_with($paymentType, 'suborder_')
            && $paymentType !== 'tip'
            && !str_contains($source, 'tip');
        $rewards = ['token' => null, 'points' => 0.0, 'discount' => 0.0, 'amount_due' => round($amount, 2)];
        if ($allowRewards) {
            try {
                $rewards = $this->reserveRewardsForPost($order, $businessId, $amount, strtoupper($paymentType) . '_EVENT_ORDER');
            } catch (\Throwable $e) {
                return ['charge' => false, 'error' => $e->getMessage()];
            }
        }
        $amountToCharge = (float)$rewards['amount_due'];
        $minimumProviderCharge = max(0, $provider->getMinimumAmount());
        if ($amountToCharge > 0.009 && $amountToCharge < $minimumProviderCharge) {
            $this->releaseRewards($rewards['token']);
            $maximumDiscount = max(0, round($amount - $minimumProviderCharge, 2));
            if ($allowRewards && $maximumDiscount > 0 && (float)($_POST['loyalty_points'] ?? 0) > 0) {
                try {
                    $rewards = $this->reserveRewardsForPost($order, $businessId, $maximumDiscount, strtoupper((string)($metadata['payment_type'] ?? 'PAYMENT')) . '_EVENT_ORDER');
                    $amountToCharge = max($minimumProviderCharge, round($amount - (float)$rewards['discount'], 2));
                } catch (\Throwable $e) {
                    return ['charge' => false, 'error' => $e->getMessage()];
                }
            } else {
                $rewards = ['token' => null, 'points' => 0.0, 'discount' => 0.0, 'amount_due' => round($amount, 2)];
                $amountToCharge = round($amount, 2);
            }
        }
        if ($amountToCharge <= 0.009) {
            $charge = (object)['id' => 'rewards-' . $rewards['token'], 'paid' => true, 'status' => 'succeeded'];
            return ['charge' => $charge, 'charged_amount' => 0.0, 'loyalty' => $rewards, 'error' => null];
        }

        $providerType = strtolower((string)($activeProvider->provider_type ?? ''));
        $clientId = (int)($order->id_client ?? 0);
        $savedMethodId = (int)($_POST['saved_payment_method_id'] ?? 0);
        $saveMethod = filter_var($_POST['save_payment_method'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $autoConsent = filter_var($_POST['auto_charge_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($providerType === 'paypal') {
            $saveMethod = false;
            $autoConsent = false;
            $savedMethodId = 0;
        }

        if ($savedMethodId > 0) {
            $session = LoginService::getSession();
            if (!$session || (int)$session->getLevel() !== 5 || (int)$session->getId() !== $clientId) {
                $this->releaseRewards($rewards['token']);
                return ['charge' => false, 'error' => 'Please log in as the order client to use a saved payment method.'];
            }

            $method = $this->methods->getActiveMethodForClientProvider($savedMethodId, $businessId, $clientId, $providerType);
            if (!$method) {
                $this->releaseRewards($rewards['token']);
                return ['charge' => false, 'error' => 'The selected saved payment method is not available for this business and provider.'];
            }

            if (!$provider->supportsChargingSavedPaymentMethods()) {
                $this->releaseRewards($rewards['token']);
                return ['charge' => false, 'error' => 'This payment provider does not support charging saved payment methods.'];
            }

            try {
                $charge = $provider->chargeSavedPaymentMethod($method, $amountToCharge, $metadata);
            } catch (\Throwable $e) {
                $this->releaseRewards($rewards['token']);
                return ['charge' => false, 'error' => 'Payment could not be processed with the saved payment method.'];
            }
            if ($charge === false) $this->releaseRewards($rewards['token']);
            return [
                'charge' => $charge,
                'saved_payment_method_id' => $charge === false ? null : $savedMethodId,
                'auto_charge_consent_id' => null,
                'error' => $charge === false ? 'Payment could not be processed with the saved payment method.' : null,
                'charged_amount' => $amountToCharge,
                'loyalty' => $rewards,
            ];
        }

        $token = (string)($_POST['customer_token'] ?? '');
        if ($token === '') {
            $this->releaseRewards($rewards['token']);
            return ['charge' => false, 'error' => 'Missing payment data.'];
        }

        $metadata['save_payment_method'] = $saveMethod || $autoConsent;
        $metadata['customer_name'] = $metadata['customer_name'] ?? '';

        $squareReusable = null;
        if ($providerType === 'square' && ($saveMethod || $autoConsent)) {
            if (!method_exists($provider, 'createReusablePaymentMethod')) {
                $this->releaseRewards($rewards['token']);
                return ['charge' => false, 'error' => 'This Square provider cannot save reusable payment methods.'];
            }
            $squareReusable = $provider->createReusablePaymentMethod($token, (string)($metadata['customer_email'] ?? ''), (string)($metadata['customer_name'] ?? ''), $metadata);
            if (!$squareReusable) {
                $this->releaseRewards($rewards['token']);
                return ['charge' => false, 'error' => 'Square could not create a reusable card-on-file.'];
            }
            $token = (string)$squareReusable['card_id'];
            // The source is now a Square card-on-file rather than the original
            // one-time nonce, so CreatePayment must include its customer ID.
            $metadata['customer_id'] = (string)$squareReusable['customer_id'];
        }

        try {
            $charge = $provider->chargeCustomer($token, $amountToCharge, $metadata);
        } catch (\Throwable $e) {
            $this->releaseRewards($rewards['token']);
            return ['charge' => false, 'error' => 'Payment could not be processed.'];
        }
        if ($charge === false) {
            $this->releaseRewards($rewards['token']);
            return ['charge' => false, 'error' => 'Payment could not be processed.'];
        }

        $card = $this->extractCardDetails($charge);
        $saved = $this->methods->recordFromSuccessfulPayment([
            'id_user_business' => $businessId,
            'id_client' => $clientId,
            'user_id' => $clientId,
            'payment_provider' => $providerType,
            'provider_customer_id' => $squareReusable['customer_id'] ?? $charge->saved_provider_customer_id ?? null,
            'provider_payment_method_id' => $squareReusable['card_id'] ?? $charge->saved_provider_payment_method_id ?? null,
            'provider_reference' => $charge->id ?? null,
            'method_type' => 'card',
            'brand' => $squareReusable['brand'] ?? $card['brand'],
            'last4' => $squareReusable['last4'] ?? $card['last4'],
            'exp_month' => $squareReusable['exp_month'] ?? $card['exp_month'],
            'exp_year' => $squareReusable['exp_year'] ?? $card['exp_year'],
            'billing_name' => $metadata['customer_name'] ?? null,
            'billing_email' => $metadata['customer_email'] ?? null,
            'source' => $metadata['source'] ?? 'order_access',
            'related_order_id' => $metadata['order_id'] ?? null,
            'related_store_order_id' => null,
            'related_payment_id' => null,
            'save_payment_method' => $saveMethod || $autoConsent,
            'auto_charge_consent' => $autoConsent,
            'metadata' => [
                'charge_id' => $charge->id ?? null,
                'payment_type' => $metadata['payment_type'] ?? null,
                'suborder_id' => $metadata['suborder_id'] ?? null,
            ],
        ]);

        return [
            'charge' => $charge,
            'saved_payment_method_id' => $saved['saved_payment_method_id'] ?? null,
            'auto_charge_consent_id' => $saved['auto_charge_consent_id'] ?? null,
            'error' => null,
            'charged_amount' => $amountToCharge,
            'loyalty' => $rewards,
        ];
    }

    public function extractCardDetails(object $charge): array
    {
        $brand = null;
        $last4 = null;
        $expMonth = null;
        $expYear = null;

        if (isset($charge->raw)) {
            $raw = $charge->raw;
            if (isset($raw->payment_method_details->card)) {
                $brand = $raw->payment_method_details->card->brand ?? null;
                $last4 = $raw->payment_method_details->card->last4 ?? null;
                $expMonth = $raw->payment_method_details->card->exp_month ?? null;
                $expYear = $raw->payment_method_details->card->exp_year ?? null;
            } elseif (is_object($raw) && method_exists($raw, 'getCardDetails') && $raw->getCardDetails() && method_exists($raw->getCardDetails(), 'getCard') && $raw->getCardDetails()->getCard()) {
                $cardObj = $raw->getCardDetails()->getCard();
                $brand = method_exists($cardObj, 'getCardBrand') ? $cardObj->getCardBrand() : null;
                $last4 = method_exists($cardObj, 'getLast4') ? $cardObj->getLast4() : null;
                $expMonth = method_exists($cardObj, 'getExpMonth') ? $cardObj->getExpMonth() : null;
                $expYear = method_exists($cardObj, 'getExpYear') ? $cardObj->getExpYear() : null;
            }
        }

        return [
            'brand' => $brand,
            'last4' => $last4,
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
        ];
    }
}
