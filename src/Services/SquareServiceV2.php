<?php

namespace App\Services;

use App\Entity\User;
use App\Utils\ErrorLogging;
use App\Utils\FormatPhone;
use App\Utils\LocationUtils;
use Exception;
use Square\SquareClient;
use Square\Environments;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Payments\Requests\GetPaymentsRequest;
use Square\Types\Money;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\Cards\Requests\CreateCardRequest;
use Square\Customers\Requests\SearchCustomersRequest;
use Square\Types\CustomerQuery;
use Square\Types\CustomerFilter;
use Square\Types\CustomerTextFilter;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;

class SquareServiceV2
{

    private SquareClient $squareClient;
    private string $locationId;

    public function __construct() {
        $token = ConfigService::$SQUARE_ACCESS_TOKEN;
        $version = '2026-01-22'; // Square API version
        
        // Configurar baseUrl según el environment
        $baseUrl = (ConfigService::$SQUARE_ENVIRONMENT ?? 'sandbox') === 'production' 
            ? Environments::Production->value 
            : Environments::Sandbox->value;
        
        $options = [
            'baseUrl' => $baseUrl
        ];
        
        $this->squareClient = new SquareClient($token, $version, $options);
        $this->locationId = ConfigService::$SQUARE_LOCATION_ID;
    }

    public function createAccount(User $user): ?object
    {
        try {
            // Square no tiene cuentas conectadas como Stripe, pero podemos crear un perfil de vendedor
            // Por ahora, retornamos un objeto con la información del usuario
            $url = LocationUtils::getBasePath();

            if (str_contains($url, "localhost") || str_contains($url, "127.0.0.1")) {
                $url = ConfigService::$SQUARE_SUPPORT_URL;
            }

            // Square maneja las cuentas de manera diferente
            // Retornamos un objeto similar para mantener compatibilidad
            return (object)[
                'id' => 'sq_' . uniqid(),
                'email' => $user->getEmail(),
                'type' => 'business',
                'created' => time(),
            ];
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function deleteAccount(string $accountId): bool
    {
        try {
            // Square no permite eliminar cuentas directamente desde la API
            // Esto se maneja desde el dashboard de Square
            return true;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return false;
        }
    }

    public function createAccountLink(string $accountId): ?object
    {
        try {
            // Square usa OAuth para conectar cuentas
            // Generamos un enlace de autorización
            $refreshUrl = LocationUtils::pathFor('panel/planner-hub/management/payments');
            $returnUrl = $refreshUrl;

            // Square OAuth URL
            $oauthUrl = "https://squareup.com/oauth2/authorize?" . http_build_query([
                'client_id' => ConfigService::$SQUARE_APPLICATION_ID,
                'scope' => 'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE',
                'session' => false,
                'state' => base64_encode(json_encode(['account_id' => $accountId])),
                'redirect_uri' => $returnUrl,
            ]);

            return (object)[
                'url' => $oauthUrl,
                'expires_at' => time() + 3600,
            ];
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function createCustomerWithCardOnConnectedAccount(string $cardToken, string $customerEmail, string $customerName, string $connectedAccountId): ?object
    {
        try {
            $body = new CreateCustomerRequest();
            $body->setGivenName($customerName);
            $body->setEmailAddress($customerEmail);

            $response = $this->squareClient->customers->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $customer = $response->getCustomer();
                
                // En Square, las tarjetas se pueden guardar después del primer pago
                // Por ahora, solo creamos el cliente. El token se usará directamente en el pago
                
                return $customer;
            } else {
                $errors = $response->getErrors();
                ErrorLogging::log(new Exception('Square API Error: ' . json_encode($errors)));
                return null;
            }
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    private function createCardForCustomer(string $customerId, string $cardToken): ?object
    {
        try {
            // En Square, para crear una tarjeta guardada necesitamos el sourceId y un objeto Card
            $card = new \Square\Types\Card();
            $card->setCustomerId($customerId);
            
            $idempotencyKey = uniqid('card_', true);
            $body = new CreateCardRequest([
                'idempotencyKey' => $idempotencyKey,
                'sourceId' => $cardToken,
                'card' => $card
            ]);

            $response = $this->squareClient->cards->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                return $response->getCard();
            } else {
                $errors = $response->getErrors();
                ErrorLogging::log(new Exception('Square API Error creating card: ' . json_encode($errors)));
                // No es crítico si falla, el pago puede hacerse directamente con el token
                return null;
            }
        } catch (Exception $e) {
            ErrorLogging::log($e);
            // No es crítico si falla, el pago puede hacerse directamente con el token
            return null;
        }
    }

    public function transferToPlatform(float $amount, string $description = 'Platform commission'): ?object
    {
        try {
            // Square maneja las comisiones de manera diferente
            // Esto se configura en el dashboard de Square
            return (object)[
                'id' => 'transfer_' . uniqid(),
                'amount' => $amount,
                'status' => 'completed',
            ];
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function chargeCustomerOnConnectedAccount(string $customerId, float $amount, string $accountId, ?string $cardToken = null): ?object
    {
        try {
            // Square requiere un sourceId (token de tarjeta) para crear el pago
            $sourceId = $cardToken;
            
            if (!$sourceId) {
                error_log("[SQUARE] No source ID (card token) available for payment");
                return null;
            }

            error_log("[SQUARE] Creating payment - Amount: {$amount}, CustomerId: {$customerId}, LocationId: {$this->locationId}, Token: " . substr($sourceId, 0, 20) . "...");

            $amountMoney = new Money();
            $amountInCents = intval($amount * 100); // Square expects cents
            $amountMoney->setAmount($amountInCents);
            $amountMoney->setCurrency('USD');
            
            error_log("[SQUARE] Amount in dollars: {$amount}, Amount in cents: {$amountInCents}");

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $sourceId,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);
            
            if ($customerId) {
                $body->setCustomerId($customerId);
            }

            error_log("[SQUARE] Payment request prepared, calling Square API...");
            error_log("[SQUARE] Request details - AmountMoney: " . $amountMoney->getAmount() . " cents (" . ($amountMoney->getAmount() / 100) . " dollars), IdempotencyKey: {$idempotencyKey}");
            $response = $this->squareClient->payments->create($body);
            error_log("[SQUARE] Square API response received");
            
            // Verificar el monto en la respuesta de Square
            if ($response->getPayment()) {
                $payment = $response->getPayment();
                $paidAmount = $payment->getAmountMoney();
                if ($paidAmount) {
                    $paidCents = $paidAmount->getAmount();
                    $paidDollars = $paidCents / 100;
                    error_log("[SQUARE] Payment confirmed - Amount charged: {$paidCents} cents ({$paidDollars} dollars)");
                }
            }

            $errors = $response->getErrors();
            if ($errors && !empty($errors)) {
                $errorDetails = [];
                foreach ($errors as $error) {
                    $errorDetails[] = [
                        'category' => $error->getCategory(),
                        'code' => $error->getCode(),
                        'detail' => $error->getDetail(),
                        'field' => $error->getField(),
                    ];
                }
                error_log("[SQUARE] API Errors: " . json_encode($errorDetails));
                ErrorLogging::log(new Exception('Square API Error: ' . json_encode($errorDetails)));
                
                // Crear objeto de error similar a Stripe
                $errorPayment = (object)[
                    'id' => null,
                    'status' => 'payment_failed',
                    '_error_details' => [
                        'type' => 'api_error',
                        'message' => $errors[0]->getDetail() ?? 'Payment failed',
                        'code' => $errors[0]->getCode() ?? 'UNKNOWN',
                        'category' => $errors[0]->getCategory() ?? 'UNKNOWN',
                    ],
                ];
                
                return $errorPayment;
            }
            
            $payment = $response->getPayment();
            if (!$payment) {
                error_log("[SQUARE] Payment object is null in response");
                return null;
            }
            
            error_log("[SQUARE] Payment created successfully - ID: " . $payment->getId() . ", Status: " . $payment->getStatus());
                
                // Extraer detalles de la tarjeta
                $cardBrand = null;
                $cardLast4 = null;
                $cardExpMonth = null;
                $cardExpYear = null;
                
                $cardDetails = $payment->getCardDetails();
                if ($cardDetails) {
                    $card = $cardDetails->getCard();
                    if ($card) {
                        $cardBrand = $card->getCardBrand();
                        $cardLast4 = $card->getLast4();
                        $cardExpMonth = $card->getExpMonth();
                        $cardExpYear = $card->getExpYear();
                    }
                }
                
                // Crear objeto similar a PaymentIntent de Stripe para compatibilidad
                $paymentIntent = (object)[
                    'id' => $payment->getId(),
                    'status' => strtolower($payment->getStatus() ?? 'unknown'),
                    'amount' => $payment->getAmountMoney()?->getAmount() ? $payment->getAmountMoney()->getAmount() / 100 : 0,
                    'currency' => $payment->getAmountMoney()?->getCurrency() ?? 'USD',
                    'customer' => $payment->getCustomerId(),
                    'payment_method' => (object)[
                        'id' => $payment->getSourceType() ?? 'card',
                        'type' => 'card',
                    ],
                    'latest_charge' => (object)[
                        'id' => $payment->getId(),
                        'status' => strtolower($payment->getStatus() ?? 'unknown'),
                        'payment_method_details' => (object)[
                            'card' => (object)[
                                'brand' => $cardBrand ?? ($payment->getSourceType() ?? 'card'),
                                'last4' => $cardLast4,
                                'exp_month' => $cardExpMonth,
                                'exp_year' => $cardExpYear,
                            ],
                        ],
                    ],
                ];

                return $paymentIntent;
        } catch (SquareApiException $e) {
            $errorDetails = [
                'type' => 'api_error',
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'action' => 'retry'
            ];
            
            error_log("[SQUARE] API Error - " . json_encode($errorDetails));
            ErrorLogging::log($e);
            return null;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getCustomerOnConnectedAccount(string $email, string $accountId): ?object
    {
        try {
            // Square usa un objeto Query para buscar clientes
            $emailFilter = new CustomerTextFilter();
            $emailFilter->setExact($email);
            
            $filter = new CustomerFilter();
            $filter->setEmailAddress($emailFilter);
            
            $query = new CustomerQuery();
            $query->setFilter($filter);
            
            $body = new SearchCustomersRequest();
            $body->setQuery($query);
            
            $response = $this->squareClient->customers->search($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $customers = $response->getCustomers();
                return $customers[0] ?? null;
            } else {
                $errors = $response->getErrors();
                ErrorLogging::log(new Exception('Square API Error: ' . json_encode($errors)));
                return null;
            }
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function updateCustomerSourceOnConnectedAccount($customerId, $accountId, $source): bool
    {
        try {
            // Square maneja las tarjetas de manera diferente
            // Necesitarías crear una nueva tarjeta
            return $this->createCardForCustomer($customerId, $source) !== null;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return false;
        }
    }

    public function refundChargeOnConnectedAccount(string $chargeId, string $accountId, float $amount): ?object
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency('USD');

            $idempotencyKey = uniqid('refund_', true);
            $body = new RefundPaymentRequest([
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
                'paymentId' => $chargeId,
            ]);

            $response = $this->squareClient->refunds->refundPayment($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                return $response->getRefund();
            } else {
                $errors = $response->getErrors();
                ErrorLogging::log(new Exception('Square API Error: ' . json_encode($errors)));
                return null;
            }
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getPaymentMethod(string $paymentMethodId, string $accountId): ?object
    {
        try {
            // Square no tiene PaymentMethod como Stripe
            // Retornamos un objeto compatible
            return (object)[
                'id' => $paymentMethodId,
                'type' => 'card',
            ];
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getPaymentIntent(string $paymentIntentId, string $accountId): ?object
    {
        try {
            $request = new GetPaymentsRequest([
                'paymentId' => $paymentIntentId
            ]);
            $response = $this->squareClient->payments->get($request);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $payment = $response->getPayment();
                
                // Convertir a formato similar a PaymentIntent de Stripe
                return (object)[
                    'id' => $payment->getId(),
                    'status' => strtolower($payment->getStatus() ?? 'unknown'),
                    'amount' => $payment->getAmountMoney()?->getAmount() ? $payment->getAmountMoney()->getAmount() / 100 : 0,
                    'currency' => $payment->getAmountMoney()?->getCurrency() ?? 'USD',
                    'customer' => $payment->getCustomerId(),
                    'payment_method' => (object)[
                        'id' => $payment->getSourceType() ?? 'card',
                        'type' => 'card',
                    ],
                    'latest_charge' => (object)[
                        'id' => $payment->getId(),
                        'status' => strtolower($payment->getStatus() ?? 'unknown'),
                    ],
                ];
            } else {
                $errors = $response->getErrors();
                ErrorLogging::log(new Exception('Square API Error: ' . json_encode($errors)));
                return null;
            }
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getCharge(string $chargeId, string $accountId): ?object
    {
        return $this->getPaymentIntent($chargeId, $accountId);
    }

    public function createPaymentIntent(int $amount, string $currency, string $paymentMethodId, string $accountId, array $metadata = []): ?object
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount($amount);
            $amountMoney->setCurrency(strtoupper($currency));

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $paymentMethodId,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);
            
            if (isset($metadata['order_id'])) {
                $body->setNote("Tip payment for Order VNV-341" . $metadata['order_id']);
            }

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $payment = $response->getPayment();
                
                return (object)[
                    'id' => $payment->getId(),
                    'status' => strtolower($payment->getStatus() ?? 'unknown'),
                    'amount' => $payment->getAmountMoney()?->getAmount() ? $payment->getAmountMoney()->getAmount() / 100 : 0,
                    'currency' => $payment->getAmountMoney()?->getCurrency() ?? 'USD',
                    'payment_method' => (object)[
                        'id' => $payment->getSourceType() ?? 'card',
                        'type' => 'card',
                    ],
                    'latest_charge' => (object)[
                        'id' => $payment->getId(),
                        'status' => strtolower($payment->getStatus() ?? 'unknown'),
                    ],
                ];
            } else {
                $errors = $response->getErrors();
                ErrorLogging::log(new Exception('Square API Error: ' . json_encode($errors)));
                
                $errorPayment = (object)[
                    'id' => null,
                    'status' => 'payment_failed',
                    '_error_details' => [
                        'type' => 'api_error',
                        'message' => $errors[0]->getDetail() ?? 'Payment failed',
                        'code' => $errors[0]->getCode() ?? 'UNKNOWN',
                    ],
                ];
                
                return $errorPayment;
            }
        } catch (SquareApiException $e) {
            $errorDetails = [
                'type' => 'api_error',
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'action' => 'retry'
            ];
            
            error_log("[SQUARE] Payment Intent API Error - " . json_encode($errorDetails));
            ErrorLogging::log($e);
            return null;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function analyzePaymentOutcome($paymentIntentOrCharge): array
    {
        if (!$paymentIntentOrCharge) {
            return [
                'type' => 'unknown',
                'status' => 'failed',
                'message' => 'No payment information available',
                'action' => 'contact_support'
            ];
        }

        $status = $paymentIntentOrCharge->status ?? 'failed';
        
        $result = [
            'type' => $status === 'COMPLETED' ? 'succeeded' : 'failed',
            'status' => strtolower($status),
            'message' => $this->getSquareErrorMessage($status),
            'action' => $status === 'COMPLETED' ? 'none' : 'retry'
        ];

        return $result;
    }

    private function getSquareErrorMessage(string $status): string
    {
        $messages = [
            'COMPLETED' => 'Payment completed successfully',
            'APPROVED' => 'Payment approved',
            'PENDING' => 'Payment is pending',
            'FAILED' => 'Payment failed. Please try again or use a different payment method.',
            'CANCELED' => 'Payment was canceled',
        ];

        return $messages[$status] ?? 'Payment failed. Please try again or contact support.';
    }

    public function getPaymentErrorMessage($paymentIntentOrCharge): ?string
    {
        if (!$paymentIntentOrCharge) {
            return 'Payment processing failed. Please try again or contact support.';
        }

        $outcome = $this->analyzePaymentOutcome($paymentIntentOrCharge);
        return $outcome['message'] ?? 'Payment failed. Please try again or contact support.';
    }

    public function shouldRetryPayment($paymentIntentOrCharge): bool
    {
        if (!$paymentIntentOrCharge) {
            return true;
        }

        $outcome = $this->analyzePaymentOutcome($paymentIntentOrCharge);
        $action = $outcome['action'] ?? 'retry';

        return in_array($action, ['retry', 'retry_with_different_card', 'retry_later']);
    }
}
