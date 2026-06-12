<?php

namespace App\Services;

use App\Repositories\ClientAutoChargeConsentsRepository;
use App\Repositories\ClientSavedPaymentMethodsRepository;

class ClientPaymentMethodService
{
    public const CONSENT_VERSION = '2026-06-10';

    private ClientSavedPaymentMethodsRepository $methods;
    private ClientAutoChargeConsentsRepository $consents;

    public function __construct()
    {
        $this->methods = new ClientSavedPaymentMethodsRepository();
        $this->consents = new ClientAutoChargeConsentsRepository();
    }

    public function listClientSavedPaymentMethods(int $businessId, int $clientId): array
    {
        try {
            return $this->methods->getActiveForClient($businessId, $clientId);
        } catch (\Throwable $e) {
            error_log('Client payment methods unavailable: ' . $e->getMessage());
            return [];
        }
    }

    public function listClientSavedPaymentMethodsForProvider(int $businessId, int $clientId, string $provider): array
    {
        $provider = strtolower(trim($provider));
        return array_values(array_filter(
            $this->listClientSavedPaymentMethods($businessId, $clientId),
            fn($method) => strtolower((string)($method->payment_provider ?? '')) === $provider
        ));
    }

    public function listClientSavedPaymentMethodsAcrossBusinesses(int $clientId): array
    {
        try {
            return $this->methods->getActiveForClientAcrossBusinesses($clientId);
        } catch (\Throwable $e) {
            error_log('Client payment methods unavailable: ' . $e->getMessage());
            return [];
        }
    }

    public function getActiveMethodForClientProvider(int $methodId, int $businessId, int $clientId, string $provider): ?object
    {
        try {
            return $this->methods->getActiveByIdForClientBusinessProvider($methodId, $businessId, $clientId, strtolower($provider));
        } catch (\Throwable $e) {
            error_log('Client payment method scoped lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public function getActiveConsentForMethod(int $businessId, int $clientId, int $methodId): ?object
    {
        try {
            return $this->consents->getActiveForMethod($businessId, $clientId, $methodId);
        } catch (\Throwable $e) {
            error_log('Client auto-charge consent lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public function revokeConsentForMethod(int $businessId, int $clientId, int $methodId): bool
    {
        try {
            return $this->consents->revokeForMethod($businessId, $clientId, $methodId);
        } catch (\Throwable $e) {
            error_log('Client auto-charge consent revoke failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deactivateMethod(int $methodId, int $clientId): bool
    {
        try {
            return $this->methods->deactivateForClient($methodId, $clientId);
        } catch (\Throwable $e) {
            error_log('Client payment method deactivate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function saveClientPaymentMethod(array $data): ?int
    {
        $businessId = (int)($data['id_user_business'] ?? 0);
        $clientId = (int)($data['id_client'] ?? $data['user_id'] ?? 0);
        $provider = strtolower(trim((string)($data['payment_provider'] ?? '')));
        $customerId = $data['provider_customer_id'] ?? null;
        $methodId = $data['provider_payment_method_id'] ?? null;

        if ($businessId <= 0 || $clientId <= 0 || $provider === '') {
            return null;
        }

        if (empty($customerId) && empty($methodId) && empty($data['provider_reference'])) {
            return null;
        }

        try {
            $duplicate = $this->methods->findDuplicate($businessId, $clientId, $provider, $customerId, $methodId);
        } catch (\Throwable $e) {
            error_log('Client payment method lookup failed: ' . $e->getMessage());
            return null;
        }
        if ($duplicate) {
            if (($duplicate->status ?? '') !== 'ACTIVE') {
                $this->methods->update(['status' => 'ACTIVE'], ['id' => (int)$duplicate->id]);
            }
            return (int)$duplicate->id;
        }

        $payload = [
            'id_user_business' => $businessId,
            'id_client' => $clientId,
            'user_id' => $data['user_id'] ?? $clientId,
            'payment_provider' => $provider,
            'provider_customer_id' => $customerId,
            'provider_payment_method_id' => $methodId,
            'provider_reference' => $data['provider_reference'] ?? null,
            'method_type' => $data['method_type'] ?? 'card',
            'brand' => $data['brand'] ?? null,
            'last4' => $data['last4'] ?? null,
            'exp_month' => $data['exp_month'] ?? null,
            'exp_year' => $data['exp_year'] ?? null,
            'billing_name' => $data['billing_name'] ?? null,
            'billing_email' => $data['billing_email'] ?? null,
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'status' => 'ACTIVE',
            'source' => $data['source'] ?? null,
            'metadata_json' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $created = $this->methods->add($payload);
        } catch (\Throwable $e) {
            error_log('Client payment method save failed: ' . $e->getMessage());
            return null;
        }

        if (!$created) {
            return null;
        }

        return $this->methods->getLastId();
    }

    public function recordAutoChargeConsent(array $data): ?int
    {
        $businessId = (int)($data['id_user_business'] ?? 0);
        $clientId = (int)($data['id_client'] ?? $data['user_id'] ?? 0);
        $methodId = (int)($data['saved_payment_method_id'] ?? 0);
        $provider = strtolower(trim((string)($data['payment_provider'] ?? '')));

        if ($businessId <= 0 || $clientId <= 0 || $provider === '') {
            return null;
        }

        if ($methodId > 0) {
            try {
                if ($this->consents->getActiveForMethod($businessId, $clientId, $methodId)) {
                    return null;
                }
            } catch (\Throwable $e) {
                error_log('Client auto-charge consent lookup failed: ' . $e->getMessage());
                return null;
            }
        }

        $text = $data['consent_text'] ?? 'I authorize this business to charge my saved payment method for balances, approved orders, recurring charges, tips or pending payments related to my services or purchases.';

        $payload = [
            'id_user_business' => $businessId,
            'id_client' => $clientId,
            'user_id' => $data['user_id'] ?? $clientId,
            'payment_provider' => $provider,
            'saved_payment_method_id' => $methodId > 0 ? $methodId : null,
            'consent_scope' => $data['consent_scope'] ?? 'orders_store_balances_tips_future_purchases',
            'consent_text' => $text,
            'consent_version' => $data['consent_version'] ?? self::CONSENT_VERSION,
            'accepted_at' => date('Y-m-d H:i:s'),
            'status' => 'ACTIVE',
            'source' => $data['source'] ?? null,
            'ip_address' => $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            'related_order_id' => $data['related_order_id'] ?? null,
            'related_store_order_id' => $data['related_store_order_id'] ?? null,
            'related_payment_id' => $data['related_payment_id'] ?? null,
            'metadata_json' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $created = $this->consents->add($payload);
        } catch (\Throwable $e) {
            error_log('Client auto-charge consent save failed: ' . $e->getMessage());
            return null;
        }

        if (!$created) {
            return null;
        }

        return $this->consents->getLastId();
    }

    public function recordFromSuccessfulPayment(array $data): array
    {
        $saveMethod = !empty($data['save_payment_method']);
        $acceptConsent = !empty($data['auto_charge_consent']);
        $savedMethodId = (int)($data['saved_payment_method_id'] ?? 0);

        if (!$saveMethod && !$acceptConsent) {
            return [
                'saved_payment_method_id' => $savedMethodId > 0 ? $savedMethodId : null,
                'auto_charge_consent_id' => null,
            ];
        }

        if ($savedMethodId <= 0 && ($saveMethod || $acceptConsent)) {
            $savedMethodId = (int)($this->saveClientPaymentMethod($data) ?? 0);
        }

        $consentId = null;
        if ($acceptConsent && $savedMethodId > 0) {
            $data['saved_payment_method_id'] = $savedMethodId;
            $consentId = $this->recordAutoChargeConsent($data);
        }

        return [
            'saved_payment_method_id' => $savedMethodId > 0 ? $savedMethodId : null,
            'auto_charge_consent_id' => $consentId,
        ];
    }
}
