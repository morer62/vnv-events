<?php

namespace App\Services;

use Square\SquareClient;
use Square\Environments;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Types\Money;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;

class SquareService
{
    private string $squareBaseUrl;
    private string $accessToken;
    private SquareClient $squareClient;
    private string $locationId;

    public function __construct()
    {
        $this->squareBaseUrl = $_ENV["SQUARE_BASE"] ?? "https://connect.squareup.com";
        $this->accessToken = $_ENV["SQUARE_ACCESS_TOKEN"] ?? "";
        $this->locationId = $_ENV["SQUARE_LOCATION_ID"] ?? "";
        
        $token = $this->accessToken;
        $version = '2026-01-22';
        
        $baseUrl = ($_ENV["SQUARE_ENVIRONMENT"] ?? 'sandbox') === 'production' 
            ? Environments::Production->value 
            : Environments::Sandbox->value;
        
        $options = [
            'baseUrl' => $baseUrl
        ];
        
        $this->squareClient = new SquareClient($token, $version, $options);
    }

    public function chargeUserToken(string $token, float $amount, string $currency = "usd"): bool
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency(strtoupper($currency));

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $token,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $payment = $response->getPayment();
                return $payment && $payment->getStatus() === 'COMPLETED';
            }

            return false;
        } catch (\Exception $e) {
            error_log("Square charge error: " . $e->getMessage());
            return false;
        }
    }

    public function createCustomerWithCard(string $token, string $email): ?string
    {
        try {
            $body = new CreateCustomerRequest();
            $body->setEmailAddress($email);

            $response = $this->squareClient->customers->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $customer = $response->getCustomer();
                return $customer ? $customer->getId() : null;
            }

            return null;
        } catch (\Exception $e) {
            error_log("Square create customer error: " . $e->getMessage());
            return null;
        }
    }

    public function createCustomerWithCardOnConnectedAccount($cardToken, $email, $name, $accountId)
    {
        try {
            error_log("⚙️ [DEBUG] Intentando crear cliente Square con:");
            error_log("Token: $cardToken");
            error_log("Email: $email");
            error_log("Name: $name");
            error_log("Account ID: $accountId");

            $body = new CreateCustomerRequest();
            $body->setGivenName($name);
            $body->setEmailAddress($email);

            $response = $this->squareClient->customers->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $customer = $response->getCustomer();
                if ($customer) {
                    error_log("✅ Cliente Square creado correctamente: " . $customer->getId());
                    return $customer->getId();
                }
            } else {
                $errors = $response->getErrors();
                error_log("❌ Square API Error: " . json_encode($errors));
                return null;
            }
        } catch (\Throwable $e) {
            echo "<pre style='color:red; background:#fee; padding:20px;'>";
            echo "❌ Square Exception: " . $e->getMessage() . "\n";
            echo "Archivo: " . $e->getFile() . "\n";
            echo "Línea: " . $e->getLine() . "\n";
            echo "</pre>";

            error_log("❌ Square Exception: " . $e->getMessage());
            error_log("Archivo: " . $e->getFile());
            error_log("Línea: " . $e->getLine());

            return null;
        }
    }

    public function chargeCardToConnectedAccount($paymentMethodId, $amount, $connectedAccountId)
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(round($amount * 100));
            $amountMoney->setCurrency('USD');

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $paymentMethodId,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                return $response->getPayment();
            }

            return null;
        } catch (\Exception $e) {
            error_log("Square charge error: " . $e->getMessage());
            return null;
        }
    }

    public function chargeTokenToConnectedAccount($token, $amount, $accountId)
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency('USD');

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $token,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                return $response->getPayment();
            }

            return null;
        } catch (\Throwable $e) {
            error_log("Square charge failed: " . $e->getMessage());
            return null;
        }
    }

    public function createCharge(string $token, float $amount, string $currency = "usd"): string
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency(strtoupper($currency));

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $token,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $payment = $response->getPayment();
                if ($payment) {
                    return "Payment Successful: " . $payment->getId();
                }
            } else {
                $errors = $response->getErrors();
                return "Error Processing Payment: " . json_encode($errors);
            }
        } catch (\Exception $e) {
            return "Error Processing Payment: " . $e->getMessage();
        }
        
        return "Error Processing Payment: Unknown error";
    }

    public function createChargeV1(string $token, float $amount, string $currency = "usd"): bool|string
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency(strtoupper($currency));

            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $token,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                $payment = $response->getPayment();
                if ($payment && $payment->getStatus() === 'COMPLETED') {
                    return $payment->getId();
                }
            }

            return false;
        } catch (\Exception $e) {
            error_log("Square charge error: " . $e->getMessage());
            return false;
        }
    }

    public function createExpressAccount(string $email): ?string
    {
        try {
            // Square no tiene "express accounts" como Stripe
            // Retornamos un ID simulado para compatibilidad
            return 'sq_' . uniqid();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function generateAccountLink(string $accountId, string $refreshUrl, string $returnUrl): ?string
    {
        try {
            // Square usa OAuth para conectar cuentas
            $oauthUrl = "https://squareup.com/oauth2/authorize?" . http_build_query([
                'client_id' => $_ENV["SQUARE_APPLICATION_ID"] ?? "",
                'scope' => 'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE',
                'session' => false,
                'state' => base64_encode(json_encode(['account_id' => $accountId])),
                'redirect_uri' => $returnUrl,
            ]);

            return $oauthUrl;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function chargeCustomerOnConnectedAccount($customerId, $amount, $accountId)
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency('USD');

            // Necesitamos un sourceId (token de tarjeta) para crear el pago
            // Si solo tenemos customerId, no podemos crear el pago directamente
            // Esto requeriría obtener una tarjeta guardada del cliente
            error_log("[SQUARE] chargeCustomerOnConnectedAccount requires a card token, customerId alone is not sufficient");
            return null;
        } catch (\Throwable $e) {
            error_log("Square charge error: " . $e->getMessage());
            return null;
        }
    }

    public function chargeUserTokenToConnectedAccount(string $customerId, float $amount, string $connectedAccountId)
    {
        try {
            $amountMoney = new Money();
            $amountMoney->setAmount(intval($amount * 100));
            $amountMoney->setCurrency('USD');

            // En Square, necesitamos un sourceId (token de tarjeta)
            // Si customerId es en realidad un token, lo usamos
            $idempotencyKey = uniqid('payment_', true);
            $body = new CreatePaymentRequest([
                'sourceId' => $customerId,
                'idempotencyKey' => $idempotencyKey,
                'amountMoney' => $amountMoney,
            ]);
            $body->setLocationId($this->locationId);
            $body->setCustomerId($customerId);

            $response = $this->squareClient->payments->create($body);

            if (!$response->getErrors() || empty($response->getErrors())) {
                return $response->getPayment();
            }

            return null;
        } catch (\Exception $e) {
            error_log("Square charge error: " . $e->getMessage());
            return null;
        }
    }

    public function getAccountBalance(string $accountId): ?array
    {
        try {
            // Square no expone el balance directamente desde la API
            // Esto se maneja desde el dashboard
            return [
                'available' => 0,
                'pending' => 0
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
