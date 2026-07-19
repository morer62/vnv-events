<?php

namespace App\Services\Payment;

use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe Payment Provider
 * Uses direct Stripe API (no Stripe Connect)
 * Credentials are loaded from database per owner
 */
class StripeProvider extends AbstractPaymentProvider
{
    private StripeClient $stripe;

    public function __construct(object $credentials)
    {
        parent::__construct($credentials);
        
        // Initialize Stripe with credentials from database
        $this->stripe = new StripeClient($credentials->api_key);
    }

    public function getProviderType(): string
    {
        return 'stripe';
    }

    public function getProviderName(): string
    {
        return 'Stripe';
    }

    /**
     * Charge a customer using a payment token
     */
    public function chargeCustomer(string $token, float $amount, array $metadata = []): object|false
    {
        try {
            if (!$this->validateAmount($amount)) {
                return false;
            }

            $description = $metadata['description'] ?? 'Payment to VNV Events';
            $metadata = array_filter($metadata, fn($key) => $key !== 'description', ARRAY_FILTER_USE_KEY);

            $savedCustomerId = null;
            $savePaymentMethod = !empty($metadata['save_payment_method']) && !empty($metadata['customer_email']);

            $chargeParams = [
                'amount' => $this->toCents($amount),
                'currency' => strtolower($this->currency),
                'description' => $description,
                'metadata' => $metadata
            ];

            if ($savePaymentMethod) {
                $customer = $this->stripe->customers->create([
                    'source' => $token,
                    'email' => trim((string)$metadata['customer_email']),
                    'name' => trim((string)($metadata['customer_name'] ?? '')),
                    'metadata' => [
                        'source' => 'order_access',
                        'reference_id' => (string)($metadata['reference_id'] ?? ''),
                    ],
                ]);
                $savedCustomerId = $customer->id;
                $chargeParams['customer'] = $savedCustomerId;
            } else {
                $chargeParams['source'] = $token;
            }

            if (!empty($metadata['customer_email'])) {
                $chargeParams['receipt_email'] = trim((string)$metadata['customer_email']);
            }

            if (!empty($metadata['billing_zip'])) {
                $chargeParams['metadata']['billing_zip'] = trim((string)$metadata['billing_zip']);
            }

            // Create charge directly (no Stripe Connect)
            $charge = $this->stripe->charges->create($chargeParams);

            return (object) [
                'id' => $charge->id,
                'amount' => $this->fromCents($charge->amount),
                'currency' => strtoupper($charge->currency),
                'status' => $charge->status,
                'paid' => $charge->paid,
                'created' => $charge->created,
                'payment_method' => $charge->payment_method_details->type ?? 'card',
                'saved_provider_customer_id' => $savedCustomerId,
                'saved_provider_payment_method_id' => null,
                'raw' => $charge
            ];

        } catch (ApiErrorException $e) {
            $this->logError("Charge failed for amount $amount", $e);
            return false;
        } catch (\Exception $e) {
            $this->logError("Unexpected error during charge", $e);
            return false;
        }
    }

    /**
     * Create a customer with payment method
     */
    public function createCustomer(string $email, string $name, array $metadata = []): ?string
    {
        try {
            $customer = $this->stripe->customers->create([
                'email' => $email,
                'name' => $name,
                'description' => $metadata['description'] ?? null,
                'metadata' => array_filter($metadata, fn($key) => $key !== 'description', ARRAY_FILTER_USE_KEY)
            ]);

            return $customer->id;

        } catch (ApiErrorException $e) {
            $this->logError("Failed to create customer: $email", $e);
            return null;
        }
    }

    public function supportsSavedPaymentMethods(): bool
    {
        return true;
    }

    public function supportsChargingSavedPaymentMethods(): bool
    {
        return true;
    }

    public function chargeSavedPaymentMethod(object $savedMethod, float $amount, array $metadata = []): object|false
    {
        $customerId = (string)($savedMethod->provider_customer_id ?? '');
        $paymentMethodId = (string)($savedMethod->provider_payment_method_id ?? '');

        if ($customerId !== '' && $paymentMethodId !== '') {
            return $this->chargeCustomerWithPaymentMethod($customerId, $paymentMethodId, $amount, $metadata);
        }

        if ($customerId !== '') {
            try {
                if (!$this->validateAmount($amount)) {
                    return false;
                }

                $description = $metadata['description'] ?? 'Payment to VNV Events';
                $metadata = array_filter($metadata, fn($key) => $key !== 'description', ARRAY_FILTER_USE_KEY);
                $charge = $this->stripe->charges->create([
                    'amount' => $this->toCents($amount),
                    'currency' => strtolower($this->currency),
                    'customer' => $customerId,
                    'description' => $description,
                    'metadata' => $metadata,
                ]);

                return (object) [
                    'id' => $charge->id,
                    'amount' => $this->fromCents($charge->amount),
                    'currency' => strtoupper($charge->currency),
                    'status' => $charge->status,
                    'paid' => (bool)$charge->paid,
                    'created' => $charge->created,
                    'payment_method' => $charge->payment_method ?? null,
                    'raw' => $charge,
                ];
            } catch (ApiErrorException $e) {
                $this->logError("Saved Stripe customer charge failed for $customerId", $e);
                return false;
            }
        }

        return false;
    }

    /**
     * Attach payment method to customer and charge
     */
    public function chargeCustomerWithPaymentMethod(string $customerId, string $paymentMethodId, float $amount, array $metadata = []): object|false
    {
        try {
            if (!$this->validateAmount($amount)) {
                return false;
            }

            $description = $metadata['description'] ?? 'Payment to VNV Events';
            $metadata = array_filter($metadata, fn($key) => $key !== 'description', ARRAY_FILTER_USE_KEY);

            $intentParams = [
                'amount' => $this->toCents($amount),
                'currency' => strtolower($this->currency),
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'description' => $description,
                'metadata' => $metadata
            ];

            if (!empty($metadata['customer_email'])) {
                $intentParams['receipt_email'] = trim((string)$metadata['customer_email']);
            }

            // Create payment intent
            $intent = $this->stripe->paymentIntents->create($intentParams);

            return (object) [
                'id' => $intent->id,
                'amount' => $this->fromCents($intent->amount),
                'currency' => strtoupper($intent->currency),
                'status' => $intent->status,
                'paid' => $intent->status === 'succeeded',
                'created' => $intent->created,
                'payment_method' => $intent->payment_method,
                'raw' => $intent
            ];

        } catch (ApiErrorException $e) {
            $this->logError("Payment intent failed for customer $customerId", $e);
            return false;
        }
    }

    /**
     * Refund a payment
     */
    public function refund(string $chargeId, ?float $amount = null, ?string $reason = null): bool
    {
        try {
            $params = [
                'charge' => $chargeId
            ];

            if ($amount !== null) {
                $params['amount'] = $this->toCents($amount);
            }

            if ($reason) {
                $params['reason'] = $reason;
            }

            $refund = $this->stripe->refunds->create($params);

            return $refund->status === 'succeeded';

        } catch (ApiErrorException $e) {
            $this->logError("Refund failed for charge $chargeId", $e);
            return false;
        }
    }

    /**
     * Get account balance
     */
    public function getBalance(): ?array
    {
        try {
            $balance = $this->stripe->balance->retrieve();

            $available = 0;
            $pending = 0;

            foreach ($balance->available as $item) {
                if ($item->currency === strtolower($this->currency)) {
                    $available = $this->fromCents($item->amount);
                    break;
                }
            }

            foreach ($balance->pending as $item) {
                if ($item->currency === strtolower($this->currency)) {
                    $pending = $this->fromCents($item->amount);
                    break;
                }
            }

            return [
                'available' => $available,
                'pending' => $pending,
                'currency' => $this->currency
            ];

        } catch (ApiErrorException $e) {
            $this->logError("Failed to retrieve balance", $e);
            return null;
        }
    }

    /**
     * Validate credentials by making a test API call
     */
    public function validateCredentials(): bool
    {
        try {
            // Try to retrieve balance as a test
            $this->stripe->balance->retrieve();
            return true;

        } catch (ApiErrorException $e) {
            $this->logError("Credential validation failed", $e);
            return false;
        }
    }

    /**
     * Get Stripe supported currencies
     * Stripe supports 135+ currencies, listing most common ones
     */
    public function getSupportedCurrencies(): array
    {
        return [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 
            'MXN', 'BRL', 'CHF', 'CNY', 'HKD', 'SGD', 
            'NZD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 
            'INR', 'THB', 'MYR', 'PHP', 'ILS', 'TWD', 'KRW',
            // Additional supported currencies
            'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AWG',
            'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD', 'BND',
            'BOB', 'BSD', 'BWP', 'BYN', 'BZD', 'CDF', 'CLP', 'COP',
            'CRC', 'CVE', 'DJF', 'DOP', 'DZD', 'EGP', 'ETB', 'FJD',
            'FKP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HNL',
            'HRK', 'HTG', 'HUF', 'IDR', 'IQD', 'ISK', 'JMD', 'JOD',
            'KES', 'KGS', 'KHR', 'KMF', 'KYD', 'KZT', 'LAK', 'LBP',
            'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK',
            'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MZN', 'NAD',
            'NGN', 'NIO', 'NPR', 'PAB', 'PEN', 'PGK', 'PKR', 'PYG',
            'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR',
            'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SZL', 'TJS', 'TOP',
            'TRY', 'TTD', 'TZS', 'UAH', 'UGX', 'UYU', 'UZS', 'VND',
            'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'
        ];
    }

    /**
     * Get minimum charge amount for currency
     */
    public function getMinimumAmount(): float
    {
        // Minimum amounts per currency (in major units)
        $minimums = [
            'USD' => 0.50,
            'EUR' => 0.50,
            'GBP' => 0.30,
            'CAD' => 0.50,
            'AUD' => 0.50,
            'JPY' => 50.0,
            'MXN' => 10.0,
            'BRL' => 0.50,
            'CHF' => 0.50,
            'CNY' => 3.0,
            'HKD' => 4.0,
            'SGD' => 0.50,
            'NZD' => 0.50,
            'SEK' => 3.0,
            'NOK' => 3.0,
            'DKK' => 2.50,
            'PLN' => 2.0,
            'CZK' => 15.0,
            'INR' => 0.50,
            'THB' => 10.0,
            'KRW' => 50.0,
            'TWD' => 20.0
        ];

        return $minimums[$this->currency] ?? 0.50;
    }

    /**
     * Create a payment intent (for Stripe Elements / Payment Element)
     */
    public function createPaymentIntent(float $amount, array $metadata = []): object|false
    {
        try {
            if (!$this->validateAmount($amount)) {
                return false;
            }

            $params = [
                'amount' => $this->toCents($amount),
                'currency' => strtolower($this->currency),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'description' => $metadata['description'] ?? 'Payment to VNV Events',
            ];

            if (isset($metadata['customer_id'])) {
                $params['customer'] = $metadata['customer_id'];
            }

            if (isset($metadata['metadata'])) {
                $params['metadata'] = $metadata['metadata'];
            }

            $intent = $this->stripe->paymentIntents->create($params);

            return (object) [
                'id' => $intent->id,
                'client_secret' => $intent->client_secret,
                'amount' => $this->fromCents($intent->amount),
                'currency' => strtoupper($intent->currency),
                'status' => $intent->status,
                'raw' => $intent
            ];

        } catch (ApiErrorException $e) {
            $this->logError("Failed to create payment intent", $e);
            return false;
        }
    }

    /**
     * Retrieve a payment intent
     */
    public function retrievePaymentIntent(string $intentId): ?object
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($intentId);

            return (object) [
                'id' => $intent->id,
                'amount' => $this->fromCents($intent->amount),
                'currency' => strtoupper($intent->currency),
                'status' => $intent->status,
                'paid' => $intent->status === 'succeeded',
                'payment_method' => $intent->payment_method,
                'created' => $intent->created,
                'raw' => $intent
            ];

        } catch (ApiErrorException $e) {
            $this->logError("Failed to retrieve payment intent $intentId", $e);
            return null;
        }
    }

    /**
     * Get public key for frontend
     */
    public function getPublicKey(): string
    {
        return $this->credentials->public_key ?? '';
    }
}
