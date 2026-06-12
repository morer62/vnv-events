<?php

namespace App\Services\Payment;

use Square\SquareClient;
use Square\Environments;
use Square\Types\Money;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\Exceptions\SquareApiException;

/**
 * Square Payment Provider
 * Uses Square API with dynamic credentials per owner
 */
class SquareProvider extends AbstractPaymentProvider
{
    private SquareClient $square;
    private string $locationId;

    public function __construct(object $credentials)
    {
        parent::__construct($credentials);
        
        // Initialize Square with credentials from database
        // For Square: api_key = ACCESS_TOKEN, public_key = APPLICATION_ID
        $accessToken = trim($credentials->api_key ?? '');
        
        if (empty($accessToken)) {
            $this->logError("WARNING: Square ACCESS_TOKEN (api_key) is empty in constructor");
        }
        
        $version = '2026-01-22';
        
        // Determine environment based on credentials
        $baseUrl = ($this->environment === 'production')
            ? Environments::Production->value
            : Environments::Sandbox->value;
        
        $options = [
            'baseUrl' => $baseUrl
        ];
        
        // Only initialize SquareClient if we have a token
        if (!empty($accessToken)) {
            // Log first 10 and last 10 chars of token for debugging (without exposing full token)
            $tokenPreview = strlen($accessToken) > 20 
                ? substr($accessToken, 0, 10) . '...' . substr($accessToken, -10)
                : substr($accessToken, 0, 10) . '...';
            $this->logError("SquareClient initialized - Environment: {$this->environment}, BaseURL: {$baseUrl}, Token length: " . strlen($accessToken) . ", Token preview: {$tokenPreview}");
            $this->square = new SquareClient($accessToken, $version, $options);
        }
        
        // Location ID is required for Square
        $this->locationId = $credentials->location_id ?? '';
        
        if (empty($this->locationId)) {
            $this->logError("WARNING: Square location_id not set for provider");
        }
    }

    public function getProviderType(): string
    {
        return 'square';
    }

    public function getProviderName(): string
    {
        return 'Square';
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

            if (empty($this->locationId)) {
                $this->logError("Cannot charge: location_id is required");
                return false;
            }

            // Create Money object
            $amountMoney = new Money();
            $amountInCents = $this->toCents($amount);
            $amountMoney->setAmount($amountInCents);
            $amountMoney->setCurrency(strtoupper($this->currency));

            // Create payment request
            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $token,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney
            ]);
            $body->setLocationId($this->locationId);
            
            if (isset($metadata['note'])) {
                $body->setNote($metadata['note']);
            }
            
            if (isset($metadata['reference_id'])) {
                $body->setReferenceId($metadata['reference_id']);
            }

            // Same API as SquareServiceV2: $client->payments->create()
            $this->logError("Square charge attempt - LocationID: {$this->locationId}, Amount: {$amount}, Token: " . substr($token, 0, 20) . "...");
            $response = $this->square->payments->create($body);

            $errors = $response->getErrors();
            if ($errors && !empty($errors)) {
                $errorMsg = $errors[0]->getDetail() ?? 'Unknown error';
                $this->logError("Square payment failed: $errorMsg");
                return false;
            }

            $payment = $response->getPayment();
            if (!$payment) {
                $this->logError("Square payment response has no payment object");
                return false;
            }

            return (object) [
                'id' => $payment->getId(),
                'amount' => $this->fromCents($payment->getAmountMoney()->getAmount()),
                'currency' => $payment->getAmountMoney()->getCurrency(),
                'status' => $payment->getStatus(),
                'paid' => $payment->getStatus() === 'COMPLETED',
                'created' => $payment->getCreatedAt(),
                'payment_method' => 'card',
                'raw' => $payment
            ];

        } catch (\Square\Exceptions\SquareApiException $e) {
            $errorMessage = $e->getMessage();
            $this->logError("Square API error during charge", $e);
            
            // Check if it's an authentication error
            if (strpos($errorMessage, '401') !== false || strpos($errorMessage, 'UNAUTHORIZED') !== false) {
                $this->logError("Square authentication failed - Access Token may be invalid, expired, or missing PAYMENTS_WRITE permission. Please regenerate the token from Square Developer Dashboard.");
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logError("Unexpected error during Square charge", $e);
            return false;
        }
    }

    /**
     * Create a customer
     */
    public function createCustomer(string $email, string $name, array $metadata = []): ?string
    {
        try {
            $body = new CreateCustomerRequest();
            $body->setEmailAddress($email);
            
            // Split name into given name and family name
            $nameParts = explode(' ', $name, 2);
            $body->setGivenName($nameParts[0]);
            
            if (isset($nameParts[1])) {
                $body->setFamilyName($nameParts[1]);
            }
            
            if (isset($metadata['phone'])) {
                $body->setPhoneNumber($metadata['phone']);
            }
            
            if (isset($metadata['reference_id'])) {
                $body->setReferenceId($metadata['reference_id']);
            }

            $response = $this->square->getCustomersApi()->createCustomer($body);

            if ($response->isSuccess()) {
                return $response->getResult()->getCustomer()->getId();
            } else {
                $errors = $response->getErrors();
                $errorMsg = isset($errors[0]) ? $errors[0]->getDetail() : 'Unknown error';
                $this->logError("Failed to create Square customer: $errorMsg");
                return null;
            }

        } catch (\Square\Exceptions\SquareApiException $e) {
            $this->logError("Square API error creating customer", $e);
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
        $cardId = (string)($savedMethod->provider_payment_method_id ?? $savedMethod->provider_reference ?? '');
        if ($cardId === '') {
            return false;
        }

        if (!empty($savedMethod->provider_customer_id)) {
            $metadata['customer_id'] = (string)$savedMethod->provider_customer_id;
        }

        return $this->chargeCustomer($cardId, $amount, $metadata);
    }

    public function createReusablePaymentMethod(string $sourceId, string $email, string $name, array $metadata = []): ?array
    {
        $accessToken = trim((string)($this->credentials->api_key ?? ''));
        if ($accessToken === '' || $this->locationId === '') {
            $this->logError('Cannot create Square card-on-file: missing access token or location id.');
            return null;
        }

        $baseUrl = $this->environment === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';

        $nameParts = preg_split('/\s+/', trim($name), 2);
        $customerPayload = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'email_address' => $email,
            'given_name' => $nameParts[0] ?? '',
        ];
        if (!empty($nameParts[1])) {
            $customerPayload['family_name'] = $nameParts[1];
        }
        if (!empty($metadata['reference_id'])) {
            $customerPayload['reference_id'] = (string)$metadata['reference_id'];
        }

        $customerCh = curl_init($baseUrl . '/v2/customers');
        curl_setopt_array($customerCh, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Square-Version: 2024-12-18',
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($customerPayload),
            CURLOPT_TIMEOUT => 30,
        ]);
        $customerRaw = curl_exec($customerCh);
        $customerHttpCode = (int)curl_getinfo($customerCh, CURLINFO_HTTP_CODE);
        $customerCurlError = curl_error($customerCh);
        curl_close($customerCh);

        if ($customerRaw === false || $customerHttpCode < 200 || $customerHttpCode >= 300) {
            $this->logError('Square customer creation failed: HTTP ' . $customerHttpCode . ' ' . $customerCurlError . ' ' . (string)$customerRaw);
            return null;
        }

        $customerDecoded = json_decode((string)$customerRaw, true);
        $customerId = (string)($customerDecoded['customer']['id'] ?? '');
        if ($customerId === '') {
            return null;
        }

        $payload = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'source_id' => $sourceId,
            'card' => [
                'customer_id' => $customerId,
            ],
        ];

        $ch = curl_init($baseUrl . '/v2/cards');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Square-Version: 2024-12-18',
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            $this->logError('Square card-on-file creation failed: HTTP ' . $httpCode . ' ' . $curlError . ' ' . (string)$raw);
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        $card = $decoded['card'] ?? null;
        if (!$card || empty($card['id'])) {
            return null;
        }

        return [
            'customer_id' => $customerId,
            'card_id' => (string)$card['id'],
            'brand' => $card['card_brand'] ?? null,
            'last4' => $card['last_4'] ?? null,
            'exp_month' => $card['exp_month'] ?? null,
            'exp_year' => $card['exp_year'] ?? null,
            'raw' => $card,
        ];
    }

    /**
     * Refund a payment
     */
    public function refund(string $chargeId, ?float $amount = null, ?string $reason = null): bool
    {
        try {
            $idempotencyKey = uniqid('refund_', true);
            $body = new RefundPaymentRequest($idempotencyKey, $chargeId);
            
            if ($amount !== null) {
                $refundMoney = new Money();
                $refundMoney->setAmount($this->toCents($amount));
                $refundMoney->setCurrency(strtoupper($this->currency));
                $body->setAmountMoney($refundMoney);
            }
            
            if ($reason) {
                $body->setReason($reason);
            }

            $response = $this->square->getRefundsApi()->refundPayment($body);

            if ($response->isSuccess()) {
                return true;
            } else {
                $errors = $response->getErrors();
                $errorMsg = isset($errors[0]) ? $errors[0]->getDetail() : 'Unknown error';
                $this->logError("Square refund failed: $errorMsg");
                return false;
            }

        } catch (\Square\Exceptions\SquareApiException $e) {
            $this->logError("Square API error during refund", $e);
            return false;
        }
    }

    /**
     * Get account balance
     * Note: Square doesn't expose balance directly from API
     */
    public function getBalance(): ?array
    {
        // Square doesn't provide a direct balance API endpoint
        // Balance can only be seen in the Square Dashboard
        $this->logError("Square does not support balance retrieval via API");
        
        return [
            'available' => 0.0,
            'pending' => 0.0,
            'currency' => $this->currency,
            'note' => 'Square balance is only available in the dashboard'
        ];
    }

    /**
     * Validate credentials
     */
    public function validateCredentials(): bool
    {
        try {
            // Log what we're receiving for debugging
            $hasApiKey = isset($this->credentials->api_key);
            $apiKeyValue = $hasApiKey ? (empty($this->credentials->api_key) ? 'EMPTY' : 'SET (' . strlen($this->credentials->api_key) . ' chars)') : 'NOT SET';
            $hasLocationId = isset($this->credentials->location_id);
            $locationIdValue = $hasLocationId ? (empty($this->credentials->location_id) ? 'EMPTY' : 'SET (' . strlen($this->credentials->location_id) . ' chars)') : 'NOT SET';
            
            $this->logError("Square validation debug - api_key: {$apiKeyValue}, location_id: {$locationIdValue}, locationId property: " . (empty($this->locationId) ? 'EMPTY' : 'SET (' . strlen($this->locationId) . ' chars)'));
            
            // Basic validation: check that required credentials are present
            // For Square, api_key is the ACCESS_TOKEN
            if (empty($this->credentials->api_key)) {
                $this->logError("Square validation: api_key (ACCESS_TOKEN) is empty or not set");
                return false;
            }
            
            // Check location_id from credentials object first, then from property
            $locationId = $this->credentials->location_id ?? $this->locationId ?? '';
            if (empty($locationId)) {
                $this->logError("Square validation: location_id is empty or not set");
                return false;
            }
            
            // Verify token is not just whitespace
            $token = trim($this->credentials->api_key);
            if (strlen($token) < 10) {
                $this->logError("Square validation: ACCESS_TOKEN too short (length: " . strlen($token) . "). Minimum expected: 10 characters");
                return false;
            }
            
            // Verify location_id format (Square location IDs are typically alphanumeric)
            $locationIdTrimmed = trim($locationId);
            if (strlen($locationIdTrimmed) < 5) {
                $this->logError("Square validation: location_id too short (length: " . strlen($locationIdTrimmed) . "). Minimum expected: 5 characters");
                return false;
            }
            
            // If we have both required fields with reasonable length, consider valid
            // The real validation will happen when processing a payment
            $this->logError("Square validation: credentials appear valid - ACCESS_TOKEN length: " . strlen($token) . ", location_id length: " . strlen($locationIdTrimmed));
            return true;

        } catch (\Exception $e) {
            $this->logError("Square credential validation exception", $e);
            return false;
        }
    }

    /**
     * Get Square supported currencies
     * Square supports fewer currencies than Stripe
     */
    public function getSupportedCurrencies(): array
    {
        return [
            'USD', // United States
            'CAD', // Canada
            'EUR', // Europe
            'GBP', // United Kingdom
            'AUD', // Australia
            'JPY', // Japan
        ];
    }

    /**
     * Get minimum charge amount
     */
    public function getMinimumAmount(): float
    {
        $minimums = [
            'USD' => 1.00,
            'CAD' => 1.00,
            'EUR' => 1.00,
            'GBP' => 1.00,
            'AUD' => 1.00,
            'JPY' => 100.0,
        ];

        return $minimums[$this->currency] ?? 1.00;
    }

    /**
     * Get application ID for frontend
     */
    public function getApplicationId(): string
    {
        return $this->credentials->public_key ?? '';
    }

    /**
     * Get location ID
     */
    public function getLocationId(): string
    {
        return $this->locationId;
    }

    /**
     * List all locations for this account (useful for setup)
     * Note: This requires the Locations API which may not be available in all SDK versions
     */
    public function listLocations(): array
    {
        try {
            // Try to access locations API if available
            if (method_exists($this->square, 'getLocationsApi')) {
                $response = $this->square->getLocationsApi()->listLocations();
                
                if ($response->isSuccess()) {
                    $locations = $response->getResult()->getLocations();
                    $result = [];
                    
                    foreach ($locations as $loc) {
                        $result[] = [
                            'id' => $loc->getId(),
                            'name' => $loc->getName(),
                            'address' => $loc->getAddress(),
                            'status' => $loc->getStatus(),
                            'currency' => $loc->getCurrency()
                        ];
                    }
                    
                    return $result;
                }
            }
            
            // If Locations API is not available, return current location if set
            if (!empty($this->locationId)) {
                return [[
                    'id' => $this->locationId,
                    'name' => 'Current Location',
                    'status' => 'ACTIVE'
                ]];
            }
            
            return [];

        } catch (\Exception $e) {
            $this->logError("Failed to list Square locations", $e);
            return [];
        }
    }
}
