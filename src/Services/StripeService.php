<?php


namespace App\Services;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeService
{
    private string $stripeBaseUrl;
    private string $apiKey;


    public function __construct()
    {
        $this->stripeBaseUrl = $_ENV["STRIPE_BASE"] ?? "";
        $this->apiKey = $_ENV["STRIPE_KEY"] ?? "";
    }

    public function chargeUserToken(string $token, float $amount, string $currency = "usd"): bool
    {
        try {
            $client = new StripeClient($this->apiKey);
            $charge = $client->charges->create([
                "amount" => intval($amount * 100), // Stripe expects cents
                "currency" => $currency,
                "customer" => $token // Este es el token guardado en stripe_token
            ]);
            return $charge->paid && !$charge->refunded;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createCustomerWithCard(string $token, string $email): ?string
    {
        try {
            $client = new StripeClient($this->apiKey);

            $customer = $client->customers->create([
                'source' => $token,
                'email' => $email
            ]);

            return $customer->id;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function createCustomerWithCardOnConnectedAccount($cardToken, $email, $name, $accountId)
{
    try {
        // DEBUG LOG INICIAL
        error_log("⚙️ [DEBUG] Intentando crear cliente con:");
        error_log("Token: $cardToken");
        error_log("Email: $email");
        error_log("Name: $name");
        error_log("Account ID: $accountId");

        \Stripe\Stripe::setApiKey($_ENV["STRIPE_KEY"]);

        $customer = \Stripe\Customer::create([
            'email' => $email,
            'name' => $name,
            'source' => $cardToken,
        ], [
            'stripe_account' => $accountId
        ]);

        error_log("✅ Cliente creado correctamente: " . $customer->id);

        return $customer->id;
    } catch (\Throwable $e) {
        // MOSTRAR ERROR DIRECTO EN PANTALLA
        echo "<pre style='color:red; background:#fee; padding:20px;'>";
        echo "❌ Stripe Exception: " . $e->getMessage() . "\n";
        echo "Archivo: " . $e->getFile() . "\n";
        echo "Línea: " . $e->getLine() . "\n";
        echo "</pre>";

        // LOG DETALLADO EN error_log
        error_log("❌ Stripe Exception: " . $e->getMessage());
        error_log("Archivo: " . $e->getFile());
        error_log("Línea: " . $e->getLine());

        return null;
    }
}


    public function chargeCardToConnectedAccount($paymentMethodId, $amount, $connectedAccountId)
    {
        \Stripe\Stripe::setApiKey($_ENV['STRIPE_KEY']);

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => round($amount * 100),
                'currency' => 'usd',
                'payment_method' => $paymentMethodId,
                'confirm' => true,
                'off_session' => true,
                'transfer_data' => [
                    'destination' => $connectedAccountId
                ],
            ]);
            return $intent;
        } catch (\Exception $e) {
            return null;
        }
    }


 

    public function chargeTokenToConnectedAccount($token, $amount, $accountId)
    {
        try {
            \Stripe\Stripe::setApiKey($_ENV["STRIPE_KEY"]);

            return \Stripe\Charge::create([
                'amount' => $amount * 100,
                'currency' => 'usd',
                'source' => $token,
                'description' => 'Client payment',
            ], [
                'stripe_account' => $accountId
            ]);
        } catch (\Throwable $e) {
            error_log("Charge failed: " . $e->getMessage());
            return null;
        }
    }





    public function createCharge(string $token, float $amount, string $currency = "usd"): string
    {
        $url = "$this->stripeBaseUrl/charges"; // Example API
        $data = [
            "amount" => $amount,
            "currency" => $currency,
            "source" => $token
        ];

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); // Encode data for form submission
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey); // Authentication
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/x-www-form-urlencoded"
        ]);

        // Execute cURL request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Output response
        if ($httpCode == 200) {
            echo "Payment Successful: " . $response;
        } else {
            echo "Error Processing Payment: " . $response;
        }

        return $response;
    }

    /**
     * @throws ApiErrorException
     */
    public function createChargeV1(string $token, float $amount, string $currency = "usd"): bool|string
    {
        $client = new StripeClient($this->apiKey);

        $charge = $client->charges->create([
            "amount" => $amount * 100,
            "currency" => $currency,
            "customer" => $token
        ]);

        // Return charge ID if successful, false otherwise
        return ($charge->paid && !$charge->refunded) ? $charge->id : false;
    }

    


    public function createExpressAccount(string $email): ?string
    {
        try {
            $client = new StripeClient($this->apiKey);

            $account = $client->accounts->create([
                'type' => 'express',
                'email' => $email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ]
            ]);

            return $account->id;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function generateAccountLink(string $accountId, string $refreshUrl, string $returnUrl): ?string
    {
        try {
            $client = new StripeClient($this->apiKey);

            $link = $client->accountLinks->create([
                'account' => $accountId,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);

            return $link->url;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function chargeCustomerOnConnectedAccount($customerId, $amount, $accountId)
    {
        try {
            $charge = \Stripe\Charge::create([
                'amount' => intval($amount * 100),
                'currency' => 'usd',
                'customer' => $customerId,
            ], [
                'stripe_account' => $accountId
            ]);

            return $charge;
        } catch (\Throwable $e) {
            return null;
        }
    }



    public function chargeUserTokenToConnectedAccount(string $customerId, float $amount, string $connectedAccountId)
    {
        try {
            \Stripe\Stripe::setApiKey($_ENV["STRIPE_KEY"]);

            $charge = \Stripe\Charge::create([
                'amount' => intval($amount * 100), // en centavos
                'currency' => 'usd',
                'customer' => $customerId,
                'description' => 'VNV Events order payment',
                'transfer_data' => [
                    'destination' => $connectedAccountId,
                ],
            ], [
                'stripe_account' => $connectedAccountId
            ]);

            return $charge;
        } catch (\Exception $e) {
             
            return null;
        }
    }



    public function getAccountBalance(string $accountId): ?array
    {
        try {
            $client = new \Stripe\StripeClient($this->apiKey);

            $balance = $client->balance->retrieve([], [
                'stripe_account' => $accountId
            ]);

            return [
                'available' => $balance->available[0]->amount / 100,
                'pending' => $balance->pending[0]->amount / 100
            ];
        } catch (\Exception $e) {
            return null;
        }
    }


}
