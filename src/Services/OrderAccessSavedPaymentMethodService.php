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

    public function viewDataForOrder(object $order, int $businessId, string $providerType): array
    {
        $session = LoginService::getSession();
        $clientId = (int)($order->id_client ?? 0);
        $supportsFuture = in_array($providerType, ['stripe', 'square'], true);
        $canUseSaved = $session && (int)$session->getLevel() === 5 && (int)$session->getId() === $clientId && $supportsFuture;

        return [
            'can_use_saved_payment_methods' => $canUseSaved,
            'saved_payment_methods' => $canUseSaved ? $this->methods->listClientSavedPaymentMethodsForProvider($businessId, $clientId, $providerType) : [],
            'supports_future_payment_methods' => $supportsFuture,
            'payment_consent_text' => 'I authorize this business to charge my saved payment method for balances, approved orders, recurring charges, tips or pending payments related to my services or purchases.',
            'payment_consent_version' => ClientPaymentMethodService::CONSENT_VERSION,
        ];
    }

    public function chargeFromPost(AbstractPaymentProvider $provider, object $activeProvider, object $order, int $businessId, float $amount, array $metadata): array
    {
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
                return ['charge' => false, 'error' => 'Please log in as the order client to use a saved payment method.'];
            }

            $method = $this->methods->getActiveMethodForClientProvider($savedMethodId, $businessId, $clientId, $providerType);
            if (!$method) {
                return ['charge' => false, 'error' => 'The selected saved payment method is not available for this business and provider.'];
            }

            if (!$provider->supportsChargingSavedPaymentMethods()) {
                return ['charge' => false, 'error' => 'This payment provider does not support charging saved payment methods.'];
            }

            $charge = $provider->chargeSavedPaymentMethod($method, $amount, $metadata);
            return [
                'charge' => $charge,
                'saved_payment_method_id' => $charge === false ? null : $savedMethodId,
                'auto_charge_consent_id' => null,
                'error' => $charge === false ? 'Payment could not be processed with the saved payment method.' : null,
            ];
        }

        $token = (string)($_POST['customer_token'] ?? '');
        if ($token === '') {
            return ['charge' => false, 'error' => 'Missing payment data.'];
        }

        $metadata['save_payment_method'] = $saveMethod || $autoConsent;
        $metadata['customer_name'] = $metadata['customer_name'] ?? '';

        $squareReusable = null;
        if ($providerType === 'square' && ($saveMethod || $autoConsent)) {
            if (!method_exists($provider, 'createReusablePaymentMethod')) {
                return ['charge' => false, 'error' => 'This Square provider cannot save reusable payment methods.'];
            }
            $squareReusable = $provider->createReusablePaymentMethod($token, (string)($metadata['customer_email'] ?? ''), (string)($metadata['customer_name'] ?? ''), $metadata);
            if (!$squareReusable) {
                return ['charge' => false, 'error' => 'Square could not create a reusable card-on-file.'];
            }
            $token = (string)$squareReusable['card_id'];
        }

        $charge = $provider->chargeCustomer($token, $amount, $metadata);
        if ($charge === false) {
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
