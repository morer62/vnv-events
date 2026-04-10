<?php

namespace App\Services;

use App\Entity\User;
use App\Utils\ErrorLogging;
use App\Utils\FormatPhone;
use App\Utils\LocationUtils;
use Exception;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\StripeClient;

class StripeServiceV2
{

    private StripeClient $stripeClient;

    public function __construct() {
        $this->stripeClient = new StripeClient(ConfigService::$STRIPE_KEY);
        Stripe::setApiKey(ConfigService::$STRIPE_KEY);
    }

    public function createAccount(User $user): ?Account
    {

        try {

            $url = LocationUtils::getBasePath();

            if (str_contains($url, "localhost") || str_contains($url, "127.0.0.1")) {
                $url = ConfigService::$STRIPE_SUPPORT_URL;
            }

            $payload = [
                'type' => 'express',
                'email' => $user->getEmail(),
                'business_type'=> 'individual',
                'country' => 'US',
                'default_currency' => 'usd',
                'business_profile' => [
                    'url' => $url,
                    'support_email' => ConfigService::$STRIPE_SUPPORT_EMAIL,
                    'support_url' => ConfigService::$STRIPE_SUPPORT_URL,
                ],
                'individual' => [
                    'email' => $user->getEmail(),
                    'first_name' => $user->getName(),
                    'last_name' => $user->getLastname(),
                    'phone' => FormatPhone::formatPhone($user->getPhone()),
                ]
            ];

            return $this->stripeClient->accounts->create($payload);
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function deleteAccount(string $accountId): bool
    {
        try {
            $this->stripeClient->accounts->delete($accountId);
            return true;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return false;
        }
    }

    public function createAccountLink(string $accountId): ?AccountLink
    {

        $refreshUrl = LocationUtils::pathFor('panel/planner-hub/management/payments');
        $returnUrl = $refreshUrl;

        try {
            $payload = [
                'account' => $accountId,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ];

            return $this->stripeClient->accountLinks->create($payload);
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function createCustomerWithCardOnConnectedAccount(string $cardToken, string $customerEmail, string $customerName, string $connectedAccountId): ?Customer
    {
        try {
            $payload = [
                'email' => $customerEmail,
                'name' => $customerName,
                'metadata' => [
                    'connected_account_id' => $connectedAccountId,
                ],
                'source' => $cardToken,
            ];

            $options = [
                'stripe_account' => $connectedAccountId
            ];

            $customer = $this->stripeClient->customers->create($payload, $options);

            return $customer;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function transferToPlatform(float $amount, string $description = 'Platform commission'): ?\Stripe\Transfer
    {
        try {
            $payload = [
                'amount' => intval($amount * 100),
                'currency' => 'usd',
                'destination' => ConfigService::$STRIPE_PLATFORM_ACCOUNT_ID,
                'description' => $description
            ];

            return $this->stripeClient->transfers->create($payload);

        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function chargeCustomerOnConnectedAccount(string $customerId, float $amount, string $accountId, ?string $cardToken = null): ?PaymentIntent
    {
        try {
            $options = [
                'stripe_account' => $accountId
            ];

            $payload = [
                'amount' => intval($amount * 100),
                'currency' => 'usd',
                'customer' => $customerId,
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never'
                ],
                'expand' => ['payment_method']
            ];

            // Si se proporciona un token nuevo, crear un PaymentMethod y usarlo
            if ($cardToken) {
                try {
                    // Crear PaymentMethod desde el token
                    $paymentMethod = $this->stripeClient->paymentMethods->create([
                        'type' => 'card',
                        'card' => ['token' => $cardToken]
                    ], $options);
                    
                    // Adjuntar el PaymentMethod al customer
                    $this->stripeClient->paymentMethods->attach($paymentMethod->id, [
                        'customer' => $customerId
                    ], $options);
                    
                    // Usar el PaymentMethod específico en lugar de automatic_payment_methods
                    $payload['payment_method'] = $paymentMethod->id;
                    unset($payload['automatic_payment_methods']);
                    
                    error_log("Using new PaymentMethod from token: " . $paymentMethod->id);
                } catch (Exception $e) {
                    error_log("Error creating PaymentMethod from token: " . $e->getMessage());
                    // Continuar con automatic_payment_methods si falla
                }
            }

            $paymentIntent = $this->stripeClient->paymentIntents->create($payload, $options);
            
            // Always retrieve the PaymentIntent after creation to ensure payment_method and latest_charge are expanded
            // latest_charge contains the actual card used for this specific payment, not the customer's saved card
            if ($paymentIntent) {
                $retrieveParams = ['expand' => ['payment_method', 'latest_charge', 'latest_charge.payment_method_details']];
                $paymentIntent = $this->stripeClient->paymentIntents->retrieve(
                    $paymentIntent->id,
                    $retrieveParams,
                    $options
                );
                
                // If latest_charge exists but payment_method_details is not expanded, retrieve the charge separately
                if (isset($paymentIntent->latest_charge) && is_string($paymentIntent->latest_charge)) {
                    try {
                        $charge = $this->stripeClient->charges->retrieve(
                            $paymentIntent->latest_charge,
                            ['expand' => ['payment_method']],
                            $options
                        );
                        // Attach the expanded charge to the payment intent for easier access
                        $paymentIntent->latest_charge = $charge;
                    } catch (Exception $e) {
                        error_log("Error retrieving charge details: " . $e->getMessage());
                    }
                }
            }
            
            if ($paymentIntent && $paymentIntent->status === 'succeeded') {
                return $paymentIntent;
            }
            
            if ($paymentIntent && in_array($paymentIntent->status, ['requires_payment_method', 'canceled', 'payment_failed'])) {
                $outcome = $this->analyzePaymentOutcome($paymentIntent);
                error_log("[STRIPE] Payment failed - Type: {$outcome['type']}, Reason: {$outcome['reason']}, Message: {$outcome['message']}");
                $paymentIntent->_error_details = $outcome;
            }
            
            return $paymentIntent;

        } catch (ApiErrorException $e) {
            $errorDetails = [
                'type' => 'api_error',
                'code' => $e->getStripeCode(),
                'message' => $e->getMessage(),
                'action' => 'retry'
            ];
            
            if (isset($e->getJsonBody()['error'])) {
                $stripeError = $e->getJsonBody()['error'];
                $errorDetails['stripe_code'] = $stripeError['code'] ?? null;
                $errorDetails['stripe_type'] = $stripeError['type'] ?? null;
                $errorDetails['stripe_param'] = $stripeError['param'] ?? null;
                
                if ($stripeError['type'] === 'card_error') {
                    $errorDetails['category'] = 'card_error';
                    $errorDetails['message'] = $stripeError['message'] ?? $e->getMessage();
                } elseif ($stripeError['type'] === 'invalid_request_error') {
                    $errorDetails['category'] = 'invalid_request';
                    $errorDetails['message'] = 'Invalid payment request. Please check your payment information.';
                    $errorDetails['action'] = 'fix_data_and_retry';
                }
            }
            
            error_log("[STRIPE] API Error - " . json_encode($errorDetails));
            ErrorLogging::log($e);
            return null;
            
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }


    public function getCustomerOnConnectedAccount(string $email, string $accountId): ?Customer
    {
        $options = [
            'stripe_account' => $accountId
        ];

        $payload = [
            "query" => "email: '$email'"
        ];

        try {
            $results = $this->stripeClient->customers->search($payload, $options);

            return $results->data[0] ?? null;
        } catch (ApiErrorException $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function updateCustomerSourceOnConnectedAccount($customerId, $accountId, $source): bool
    {
        try {
            $options = [
                'stripe_account' => $accountId
            ];

            $payload = [
              'source' => $source
            ];

            $this->stripeClient->customers->update($customerId, $payload, $options);

            return true;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return false;
        }
    }

    public function refundChargeOnConnectedAccount(string $chargeId, string $accountId, float $amount): ?Refund
    {
        try {
            $payload = [
                'amount' => intval($amount * 100),
            ];

            // set charge id
            if (str_starts_with($chargeId, 'ch')) {
                $payload['charge'] = $chargeId;
            }

            // set payment intent if charge
            if (str_starts_with($chargeId, 'pi')) {
                $payload['payment_intent'] = $chargeId;
            }

            $options = [
                'stripe_account' => $accountId
            ];
            return $this->stripeClient->refunds->create($payload, $options);
        }catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getPaymentMethod(string $paymentMethodId, string $accountId): ?\Stripe\PaymentMethod
    {
        try {
            $options = [
                'stripe_account' => $accountId
            ];
            return $this->stripeClient->paymentMethods->retrieve($paymentMethodId, [], $options);
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getPaymentIntent(string $paymentIntentId, string $accountId): ?PaymentIntent
    {
        try {
            $options = [
                'stripe_account' => $accountId
            ];
            
            $retrieveParams = ['expand' => ['payment_method', 'latest_charge', 'latest_charge.payment_method_details']];
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve(
                $paymentIntentId,
                $retrieveParams,
                $options
            );
            
            if (isset($paymentIntent->latest_charge) && is_string($paymentIntent->latest_charge)) {
                try {
                    $charge = $this->stripeClient->charges->retrieve(
                        $paymentIntent->latest_charge,
                        ['expand' => ['payment_method']],
                        $options
                    );
                    $paymentIntent->latest_charge = $charge;
                } catch (Exception $e) {
                }
            }
            
            return $paymentIntent;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function getCharge(string $chargeId, string $accountId): ?Charge
    {
        try {
            $options = [
                'stripe_account' => $accountId
            ];
            
            $charge = $this->stripeClient->charges->retrieve(
                $chargeId,
                ['expand' => ['payment_method', 'payment_intent', 'payment_method_details']],
                $options
            );
            
            if (isset($charge->payment_method) && is_string($charge->payment_method)) {
                try {
                    $pm = $this->getPaymentMethod($charge->payment_method, $accountId);
                    if ($pm) {
                        $charge->payment_method = $pm;
                    }
                } catch (Exception $e) {
                }
            }
            
            return $charge;
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function createPaymentIntent(int $amount, string $currency, string $paymentMethodId, string $accountId, array $metadata = []): ?PaymentIntent
    {
        try {
            $options = [
                'stripe_account' => $accountId
            ];

            $payload = [
                'amount' => $amount,
                'currency' => $currency,
                'confirm' => true,
                'description' => "Tip payment for Order VNV-341" . ($metadata['order_id'] ?? ''),
                'metadata' => $metadata,
                'payment_method_types' => ['card'],
                'payment_method' => $paymentMethodId
            ];

            $paymentIntent = $this->stripeClient->paymentIntents->create($payload, $options);
            
            if ($paymentIntent) {
                $retrieveParams = ['expand' => ['payment_method', 'latest_charge', 'latest_charge.payment_method_details']];
                $paymentIntent = $this->stripeClient->paymentIntents->retrieve(
                    $paymentIntent->id,
                    $retrieveParams,
                    $options
                );
                
                if (isset($paymentIntent->latest_charge) && is_string($paymentIntent->latest_charge)) {
                    try {
                        $charge = $this->stripeClient->charges->retrieve(
                            $paymentIntent->latest_charge,
                            ['expand' => ['payment_method']],
                            $options
                        );
                        $paymentIntent->latest_charge = $charge;
                    } catch (Exception $e) {
                    }
                }
            }
            
            if ($paymentIntent && $paymentIntent->status === 'succeeded') {
                return $paymentIntent;
            }
            
            if ($paymentIntent && in_array($paymentIntent->status, ['requires_payment_method', 'canceled', 'payment_failed'])) {
                $outcome = $this->analyzePaymentOutcome($paymentIntent);
                error_log("[STRIPE] Payment Intent failed - Type: {$outcome['type']}, Reason: {$outcome['reason']}, Message: {$outcome['message']}");
                $paymentIntent->_error_details = $outcome;
            }
            
            return $paymentIntent;
        } catch (ApiErrorException $e) {
            $errorDetails = [
                'type' => 'api_error',
                'code' => $e->getStripeCode(),
                'message' => $e->getMessage(),
                'action' => 'retry'
            ];
            
            if (isset($e->getJsonBody()['error'])) {
                $stripeError = $e->getJsonBody()['error'];
                $errorDetails['stripe_code'] = $stripeError['code'] ?? null;
                $errorDetails['stripe_type'] = $stripeError['type'] ?? null;
                $errorDetails['stripe_param'] = $stripeError['param'] ?? null;
                
                if ($stripeError['type'] === 'card_error') {
                    $errorDetails['category'] = 'card_error';
                    $errorDetails['message'] = $stripeError['message'] ?? $e->getMessage();
                } elseif ($stripeError['type'] === 'invalid_request_error') {
                    $errorDetails['category'] = 'invalid_request';
                    $errorDetails['message'] = 'Invalid payment request. Please check your payment information.';
                    $errorDetails['action'] = 'fix_data_and_retry';
                }
            }
            
            error_log("[STRIPE] Payment Intent API Error - " . json_encode($errorDetails));
            ErrorLogging::log($e);
            return null;
            
        } catch (Exception $e) {
            ErrorLogging::log($e);
            return null;
        }
    }

    public function analyzePaymentOutcome($paymentIntentOrCharge): array
    {
        $outcome = null;
        $status = null;
        
        if ($paymentIntentOrCharge instanceof PaymentIntent) {
            $status = $paymentIntentOrCharge->status;
            if (isset($paymentIntentOrCharge->latest_charge)) {
                $charge = is_object($paymentIntentOrCharge->latest_charge) 
                    ? $paymentIntentOrCharge->latest_charge 
                    : null;
                if ($charge && isset($charge->outcome)) {
                    $outcome = $charge->outcome;
                }
            }
        } elseif ($paymentIntentOrCharge instanceof Charge) {
            $status = $paymentIntentOrCharge->status;
            if (isset($paymentIntentOrCharge->outcome)) {
                $outcome = $paymentIntentOrCharge->outcome;
            }
        }
        
        if (!$outcome) {
            return [
                'type' => 'unknown',
                'status' => $status,
                'message' => 'No outcome information available',
                'action' => 'contact_support'
            ];
        }
        
        $outcomeType = $outcome->type ?? 'unknown';
        $reason = $outcome->reason ?? null;
        $sellerMessage = $outcome->seller_message ?? null;
        $riskLevel = $outcome->risk_level ?? null;
        $adviceCode = $outcome->advice_code ?? null;
        $networkStatus = $outcome->network_status ?? null;
        $networkDeclineCode = $outcome->network_decline_code ?? null;
        
        $result = [
            'type' => $outcomeType,
            'status' => $status,
            'reason' => $reason,
            'seller_message' => $sellerMessage,
            'risk_level' => $riskLevel,
            'advice_code' => $adviceCode,
            'network_status' => $networkStatus,
            'network_decline_code' => $networkDeclineCode,
            'message' => $sellerMessage ?? 'Payment failed',
            'action' => 'retry'
        ];
        
        switch ($outcomeType) {
            case 'issuer_declined':
                $result['category'] = 'issuer_declined';
                $result['message'] = $this->getIssuerDeclineMessage($reason, $sellerMessage);
                $result['action'] = $adviceCode === 'do_not_try_again' ? 'contact_support' : 'retry_with_different_card';
                break;
                
            case 'blocked':
                $result['category'] = 'blocked';
                if ($reason === 'highest_risk_level') {
                    $result['message'] = 'Payment blocked by Stripe Radar due to high fraud risk. This payment was not sent to the card network.';
                    $result['action'] = 'review_in_dashboard';
                } elseif ($reason === 'low_probability_of_authorization') {
                    $result['message'] = 'Payment blocked by Adaptive Acceptance as it is unlikely to be authorized. This helps avoid unnecessary network costs.';
                    $result['action'] = 'retry_later';
                } else {
                    $result['message'] = $sellerMessage ?? 'Payment blocked by Stripe.';
                    $result['action'] = 'review_in_dashboard';
                }
                break;
                
            case 'invalid':
                $result['category'] = 'invalid_api_call';
                $result['message'] = 'Invalid API call. Please check your payment method data and try again.';
                $result['action'] = 'fix_data_and_retry';
                break;
                
            default:
                $result['category'] = 'unknown';
                $result['message'] = $sellerMessage ?? 'Payment failed for unknown reason.';
                $result['action'] = 'contact_support';
        }
        
        return $result;
    }
    
    private function getIssuerDeclineMessage(?string $reason, ?string $sellerMessage): string
    {
        $messages = [
            'insufficient_funds' => 'Insufficient funds. Please use a different payment method or contact your bank.',
            'lost_card' => 'Card reported as lost. Please use a different payment method.',
            'stolen_card' => 'Card reported as stolen. Please use a different payment method.',
            'expired_card' => 'Card has expired. Please use a different payment method.',
            'incorrect_cvc' => 'Incorrect security code. Please check and try again.',
            'incorrect_number' => 'Incorrect card number. Please check and try again.',
            'processing_error' => 'Processing error. Please try again or use a different payment method.',
            'generic_decline' => 'Payment declined by card issuer. Please contact your bank or use a different payment method.',
            'card_not_supported' => 'Card type not supported. Please use a different payment method.',
            'currency_not_supported' => 'Currency not supported for this card. Please use a different payment method.',
            'fraudulent' => 'Payment declined due to suspected fraud. Please contact your bank.',
            'restricted_card' => 'Card is restricted. Please contact your bank or use a different payment method.',
            'security_violation' => 'Security violation detected. Please contact your bank.',
            'service_not_allowed' => 'Service not allowed for this card. Please use a different payment method.',
            'stop_payment_order' => 'Stop payment order issued. Please contact your bank.',
            'testmode_decline' => 'Test card declined. Please use a valid test card in test mode.',
            'withdrawal_count_limit_exceeded' => 'Withdrawal count limit exceeded. Please contact your bank.',
        ];
        
        if ($reason && isset($messages[$reason])) {
            return $messages[$reason];
        }
        
        return $sellerMessage ?? 'Payment declined by card issuer. Please contact your bank or use a different payment method.';
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