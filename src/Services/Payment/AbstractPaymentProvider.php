<?php

namespace App\Services\Payment;

use App\Utils\ErrorLogging;
use App\Utils\LocationUtils;

/**
 * Abstract base class for all payment providers
 * Defines the contract that all payment providers must implement
 */
abstract class AbstractPaymentProvider
{
    protected object $credentials;
    protected string $currency;
    protected string $environment;

    /**
     * Initialize provider with credentials
     */
    public function __construct(object $credentials)
    {
        $this->credentials = $credentials;
        $this->currency = $credentials->currency ?? 'USD';
        $this->environment = $credentials->environment ?? 'sandbox';
    }

    /**
     * Get provider type (stripe, square, paypal)
     */
    abstract public function getProviderType(): string;

    /**
     * Get provider display name
     */
    abstract public function getProviderName(): string;

    /**
     * Charge a customer using a payment token/method
     * 
     * @param string $token Payment token (card token, payment method ID, etc)
     * @param float $amount Amount to charge (in major currency units, e.g., dollars)
     * @param array $metadata Additional metadata (order_id, customer_email, etc)
     * @return object|false Payment result object or false on failure
     */
    abstract public function chargeCustomer(string $token, float $amount, array $metadata = []): object|false;

    /**
     * Create a customer in the payment provider
     * 
     * @param string $email Customer email
     * @param string $name Customer name
     * @param array $metadata Additional customer data
     * @return string|null Customer ID or null on failure
     */
    abstract public function createCustomer(string $email, string $name, array $metadata = []): ?string;

    public function supportsSavedPaymentMethods(): bool
    {
        return false;
    }

    public function supportsChargingSavedPaymentMethods(): bool
    {
        return false;
    }

    public function chargeSavedPaymentMethod(object $savedMethod, float $amount, array $metadata = []): object|false
    {
        $this->logError('Saved payment method charges are not supported by this provider.');
        return false;
    }

    /**
     * Refund a payment
     * 
     * @param string $chargeId Payment/charge ID to refund
     * @param float|null $amount Amount to refund (null = full refund)
     * @param string|null $reason Refund reason
     * @return bool Success status
     */
    abstract public function refund(string $chargeId, ?float $amount = null, ?string $reason = null): bool;

    /**
     * Get account balance (if supported)
     * 
     * @return array|null ['available' => float, 'pending' => float] or null
     */
    abstract public function getBalance(): ?array;

    /**
     * Validate credentials by making a test API call
     * 
     * @return bool True if credentials are valid
     */
    abstract public function validateCredentials(): bool;

    /**
     * Get supported currencies for this provider
     * 
     * @return array Array of currency codes
     */
    abstract public function getSupportedCurrencies(): array;

    /**
     * Get minimum charge amount for current currency
     * 
     * @return float Minimum amount in major currency units
     */
    abstract public function getMinimumAmount(): float;

    /**
     * Get currency being used
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Get environment (sandbox/production)
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Check if provider is in sandbox/test mode
     */
    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    /**
     * Convert amount to cents/minor units (for APIs that require it)
     */
    protected function toCents(float $amount): int
    {
        // Some currencies don't use decimals (JPY, KRW, etc.)
        if (in_array($this->currency, ['JPY', 'KRW', 'TWD'])) {
            return (int) round($amount);
        }
        
        return (int) round($amount * 100);
    }

    /**
     * Convert from cents/minor units to major units
     */
    protected function fromCents(int $amount): float
    {
        if (in_array($this->currency, ['JPY', 'KRW', 'TWD'])) {
            return (float) $amount;
        }
        
        return $amount / 100;
    }

    /**
     * Format amount for display
     */
    public function formatAmount(float $amount): string
    {
        $decimals = in_array($this->currency, ['JPY', 'KRW', 'TWD']) ? 0 : 2;
        return number_format($amount, $decimals, '.', ',');
    }

    /**
     * Get currency symbol
     */
    public function getCurrencySymbol(): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CNY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'MXN' => 'MX$',
            'BRL' => 'R$',
            'CHF' => 'CHF',
            'HKD' => 'HK$',
            'SGD' => 'S$',
            'NZD' => 'NZ$',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
            'INR' => '₹',
            'THB' => '฿',
            'MYR' => 'RM',
            'PHP' => '₱',
            'ILS' => '₪',
            'TWD' => 'NT$',
            'KRW' => '₩'
        ];

        return $symbols[strtoupper($this->currency)] ?? strtoupper($this->currency);
    }

    /**
     * Log error for debugging
     */
    protected function logError(string $message, \Exception $e = null): void
    {
        $providerType = $this->getProviderType();
        $errorMsg = "[$providerType Provider Error] $message";
        
        // Get the log file path (same logic as ErrorLogging::init())
        $logDir = LocationUtils::getRootLocation() . '/.logs';
        $logFile = $logDir . '/app_error_' . date('Y-m-d') . '.log';
        
        // Ensure log directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        if ($e) {
            // Use ErrorLogging class to write to the correct log file
            ErrorLogging::log($e);
            $errorMsg .= " | Exception: " . $e->getMessage();
            $errorMsg .= " | File: " . $e->getFile() . ":" . $e->getLine();
        }
        
        // Log the message to the application log file
        error_log("\n" . $errorMsg, 3, $logFile);
    }

    /**
     * Validate amount before processing
     */
    protected function validateAmount(float $amount): bool
    {
        $minAmount = $this->getMinimumAmount();
        
        if ($amount < $minAmount) {
            $this->logError("Amount $amount is below minimum $minAmount for {$this->currency}");
            return false;
        }
        
        return true;
    }

    /**
     * Check if currency is supported by this provider
     */
    public function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->getSupportedCurrencies());
    }
}
