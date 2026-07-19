<?php

namespace App\Services\Payment;

/**
 * PayPal Payment Provider
 * Uses PayPal REST API with dynamic credentials per owner
 */
class PayPalProvider extends AbstractPaymentProvider
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;
    private ?int $tokenExpiry = null;

    public function __construct(object $credentials)
    {
        parent::__construct($credentials);
        
        // Set base URL based on environment
        $this->baseUrl = $this->environment === 'production'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
        
        $this->clientId = $credentials->api_key ?? ''; // PayPal Client ID
        $this->clientSecret = $credentials->api_secret ?? ''; // PayPal Client Secret
    }

    public function getProviderType(): string
    {
        return 'paypal';
    }

    public function getProviderName(): string
    {
        return 'PayPal';
    }

    /**
     * Get OAuth access token
     */
    private function getAccessToken(): ?string
    {
        // Return cached token if still valid
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        try {
            $ch = curl_init($this->baseUrl . '/v1/oauth2/token');
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $this->accessToken = $data['access_token'];
                $this->tokenExpiry = time() + ($data['expires_in'] - 60); // Refresh 1 min early
                return $this->accessToken;
            }

            $this->logError("Failed to get PayPal access token. HTTP $httpCode: $response");
            return null;

        } catch (\Exception $e) {
            $this->logError("Exception getting PayPal access token", $e);
            return null;
        }
    }

    /**
     * Make API request to PayPal
     */
    private function apiRequest(string $endpoint, string $method = 'GET', ?array $data = null): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to authenticate with PayPal'];
        }

        try {
            $ch = curl_init($this->baseUrl . $endpoint);
            
            $headers = [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json'
            ];

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => $method
            ]);

            if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            return [
                'success' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'data' => $result,
                'raw_response' => $response
            ];

        } catch (\Exception $e) {
            $this->logError("PayPal API request failed: $endpoint", $e);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Charge customer using PayPal order
     * Note: PayPal typically uses order-based flow, not direct charge like Stripe
     */
    public function chargeCustomer(string $token, float $amount, array $metadata = []): object|false
    {
        try {
            if (!$this->validateAmount($amount)) {
                return false;
            }

            // First, get the order details to check its status
            $orderResult = $this->apiRequest("/v2/checkout/orders/$token", 'GET');
            
            if (!$orderResult['success']) {
                $this->logError("Failed to retrieve PayPal order: " . json_encode($orderResult));
                return false;
            }
            
            $order = $orderResult['data'];
            $orderStatus = $order['status'] ?? '';
            
            // Check if order is already captured
            $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
            
            if ($capture && $orderStatus === 'COMPLETED') {
                // Order already captured (by PayPal SDK automatically)
                return (object) [
                    'id' => $capture['id'],
                    'amount' => (float) $capture['amount']['value'],
                    'currency' => $capture['amount']['currency_code'],
                    'status' => $capture['status'],
                    'paid' => $capture['status'] === 'COMPLETED',
                    'created' => $capture['create_time'] ?? $order['create_time'],
                    'payment_method' => 'paypal',
                    'raw' => $capture
                ];
            }
            
            // If order is APPROVED but not captured, capture it
            if ($orderStatus === 'APPROVED') {
                $captureResult = $this->apiRequest("/v2/checkout/orders/$token/capture", 'POST');
                
                if ($captureResult['success']) {
                    $capturedOrder = $captureResult['data'];
                    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0] ?? null;
                    
                    if ($capture) {
                        return (object) [
                            'id' => $capture['id'],
                            'amount' => (float) $capture['amount']['value'],
                            'currency' => $capture['amount']['currency_code'],
                            'status' => $capture['status'],
                            'paid' => $capture['status'] === 'COMPLETED',
                            'created' => $capture['create_time'],
                            'payment_method' => 'paypal',
                            'raw' => $capture
                        ];
                    }
                }
                
                $this->logError("PayPal capture failed: " . json_encode($captureResult));
                return false;
            }
            
            // Order is in an unexpected state
            $this->logError("PayPal order in unexpected state: $orderStatus");
            return false;

        } catch (\Exception $e) {
            $this->logError("PayPal charge error", $e);
            return false;
        }
    }

    /**
     * Create PayPal order (for checkout flow)
     */
    public function createOrder(float $amount, array $metadata = []): object|false
    {
        try {
            if (!$this->validateAmount($amount)) {
                return false;
            }

            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'amount' => [
                            'currency_code' => strtoupper($this->currency),
                            'value' => number_format($amount, 2, '.', '')
                        ],
                        'description' => $metadata['description'] ?? 'Payment to VNV Events'
                    ]
                ],
                'application_context' => [
                    'return_url' => $metadata['return_url'] ?? '',
                    'cancel_url' => $metadata['cancel_url'] ?? '',
                    'brand_name' => $metadata['brand_name'] ?? 'VNV Events',
                    'user_action' => 'PAY_NOW'
                ]
            ];

            $result = $this->apiRequest('/v2/checkout/orders', 'POST', $orderData);

            if ($result['success']) {
                $order = $result['data'];
                
                // Find approval URL
                $approvalUrl = null;
                foreach ($order['links'] as $link) {
                    if ($link['rel'] === 'approve') {
                        $approvalUrl = $link['href'];
                        break;
                    }
                }

                return (object) [
                    'id' => $order['id'],
                    'status' => $order['status'],
                    'approval_url' => $approvalUrl,
                    'amount' => $amount,
                    'currency' => $this->currency,
                    'raw' => $order
                ];
            }

            $this->logError("Failed to create PayPal order: " . json_encode($result));
            return false;

        } catch (\Exception $e) {
            $this->logError("PayPal order creation error", $e);
            return false;
        }
    }

    /**
     * Create a customer (PayPal doesn't have a customer object like Stripe)
     */
    public function createCustomer(string $email, string $name, array $metadata = []): ?string
    {
        // PayPal doesn't use a "customer" concept like Stripe
        // Customers are identified by their PayPal email
        // Return email as customer ID for consistency
        return $email;
    }

    public function supportsSavedPaymentMethods(): bool
    {
        return false;
    }

    public function supportsChargingSavedPaymentMethods(): bool
    {
        return false;
    }

    /**
     * Refund a payment
     */
    public function refund(string $chargeId, ?float $amount = null, ?string $reason = null): bool
    {
        try {
            $refundData = [];
            
            if ($amount !== null) {
                $refundData['amount'] = [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => strtoupper($this->currency)
                ];
            }
            
            if ($reason) {
                $refundData['note_to_payer'] = $reason;
            }

            $result = $this->apiRequest("/v2/payments/captures/$chargeId/refund", 'POST', $refundData);

            if ($result['success']) {
                $refund = $result['data'];
                return $refund['status'] === 'COMPLETED';
            }

            $this->logError("PayPal refund failed: " . json_encode($result));
            return false;

        } catch (\Exception $e) {
            $this->logError("PayPal refund error", $e);
            return false;
        }
    }

    /**
     * Get account balance
     * PayPal doesn't provide balance endpoint in REST API
     */
    public function getBalance(): ?array
    {
        $this->logError("PayPal does not support balance retrieval via REST API");
        
        return [
            'available' => 0.0,
            'pending' => 0.0,
            'currency' => $this->currency,
            'note' => 'PayPal balance is only available in the PayPal dashboard'
        ];
    }

    /**
     * Validate credentials
     */
    public function validateCredentials(): bool
    {
        $token = $this->getAccessToken();
        return $token !== null;
    }

    /**
     * Get PayPal supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        return [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY',
            'MXN', 'BRL', 'CHF', 'HKD', 'SGD', 'NZD',
            'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'INR',
            'THB', 'MYR', 'PHP', 'ILS', 'TWD',
            // Additional PayPal currencies
            'ARS', 'CLP', 'COP', 'CRC', 'EGP', 'HUF',
            'IDR', 'KRW', 'PEN', 'PYG', 'RON', 'RUB',
            'TRY', 'UYU', 'VND', 'ZAR'
        ];
    }

    /**
     * Get minimum charge amount
     */
    public function getMinimumAmount(): float
    {
        // PayPal minimum is very low for most currencies
        $minimums = [
            'USD' => 0.01,
            'EUR' => 0.01,
            'GBP' => 0.01,
            'CAD' => 0.01,
            'AUD' => 0.01,
            'JPY' => 1.0,
            'MXN' => 0.10,
            'BRL' => 0.05,
        ];

        return $minimums[$this->currency] ?? 0.01;
    }

    /**
     * Get client ID for frontend
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Retrieve order details
     */
    public function getOrder(string $orderId): ?object
    {
        try {
            $result = $this->apiRequest("/v2/checkout/orders/$orderId", 'GET');

            if ($result['success']) {
                $order = $result['data'];
                $amount = (float) $order['purchase_units'][0]['amount']['value'];

                return (object) [
                    'id' => $order['id'],
                    'status' => $order['status'],
                    'amount' => $amount,
                    'currency' => $order['purchase_units'][0]['amount']['currency_code'],
                    'payer_email' => $order['payer']['email_address'] ?? null,
                    'payer_name' => $order['payer']['name']['given_name'] ?? null,
                    'created' => $order['create_time'],
                    'raw' => $order
                ];
            }

            return null;

        } catch (\Exception $e) {
            $this->logError("Failed to retrieve PayPal order $orderId", $e);
            return null;
        }
    }
}
