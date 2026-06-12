<?php

namespace App\Services\Payment;

use App\Repositories\PaymentProvidersRepository;

/**
 * Factory class for creating payment provider instances
 * Handles dynamic loading of payment providers based on credentials
 */
class PaymentProviderFactory
{
    /**
     * Create a payment provider instance from credentials object
     * 
     * @param object $credentials Payment provider credentials from database
     * @return AbstractPaymentProvider Provider instance
     * @throws \Exception If provider type is not supported
     */
    public static function create(object $credentials): AbstractPaymentProvider
    {
        return match($credentials->provider_type) {
            'stripe' => new StripeProvider($credentials),
            'square' => new SquareProvider($credentials),
            'paypal' => new PayPalProvider($credentials),
            default => throw new \Exception("Payment provider '{$credentials->provider_type}' is not supported")
        };
    }

    /**
     * Create provider for a specific owner (uses default provider)
     * 
     * @param int $ownerId Owner user ID
     * @return AbstractPaymentProvider Provider instance
     * @throws \Exception If no provider is configured for owner
     */
    public static function createForOwner(int $ownerId): AbstractPaymentProvider
    {
        $repo = new PaymentProvidersRepository();
        $credentials = $repo->getDefaultByOwner($ownerId);
        
        if (!$credentials) {
            throw new \Exception("No default payment provider configured for owner $ownerId");
        }
        
        if (!$credentials->is_active) {
            throw new \Exception("Default payment provider is not active for owner $ownerId");
        }
        
        return self::create($credentials);
    }

    /**
     * Create provider for owner by specific type
     * 
     * @param int $ownerId Owner user ID
     * @param string $providerType Provider type (stripe, square, paypal)
     * @return AbstractPaymentProvider Provider instance
     * @throws \Exception If provider type is not configured for owner
     */
    public static function createForOwnerByType(int $ownerId, string $providerType): AbstractPaymentProvider
    {
        $repo = new PaymentProvidersRepository();
        $providers = $repo->getByType($ownerId, $providerType);
        
        if (empty($providers)) {
            throw new \Exception("No $providerType provider configured for owner $ownerId");
        }
        
        // Get first active provider of this type, or first provider if none are active
        $activeProvider = null;
        foreach ($providers as $provider) {
            if ($provider->is_active) {
                $activeProvider = $provider;
                break;
            }
        }
        
        $credentials = $activeProvider ?? $providers[0];
        
        if (!$credentials->is_active) {
            throw new \Exception("$providerType provider is not active for owner $ownerId");
        }
        
        return self::create($credentials);
    }

    /**
     * Create provider by credential ID
     * 
     * @param int $credentialId Credential ID from database
     * @param int $ownerId Owner ID (for security verification)
     * @return AbstractPaymentProvider Provider instance
     * @throws \Exception If credential not found or doesn't belong to owner
     */
    public static function createById(int $credentialId, int $ownerId): AbstractPaymentProvider
    {
        $repo = new PaymentProvidersRepository();
        $credentials = $repo->getById($credentialId, $ownerId);
        
        if (!$credentials) {
            throw new \Exception("Payment provider credential $credentialId not found for owner $ownerId");
        }
        
        if (!$credentials->is_active) {
            throw new \Exception("Payment provider credential $credentialId is not active");
        }
        
        return self::create($credentials);
    }

    /**
     * Get all active providers for an owner
     * 
     * @param int $ownerId Owner user ID
     * @return array Array of AbstractPaymentProvider instances
     */
    public static function getAllForOwner(int $ownerId): array
    {
        $repo = new PaymentProvidersRepository();
        $credentialsList = $repo->getActiveByOwner($ownerId);
        
        $providers = [];
        foreach ($credentialsList as $credentials) {
            try {
                $providers[] = self::create($credentials);
            } catch (\Exception $e) {
                error_log("Failed to create provider for owner $ownerId: " . $e->getMessage());
                continue;
            }
        }
        
        return $providers;
    }

    /**
     * Get available provider types
     * 
     * @return array Array of provider type strings
     */
    public static function getAvailableProviders(): array
    {
        return ['stripe', 'square', 'paypal'];
    }

    /**
     * Get provider display names
     * 
     * @return array Associative array of type => display name
     */
    public static function getProviderNames(): array
    {
        return [
            'stripe' => 'Stripe',
            'square' => 'Square',
            'paypal' => 'PayPal'
        ];
    }

    /**
     * Validate that a provider type is supported
     * 
     * @param string $providerType Provider type to validate
     * @return bool True if supported
     */
    public static function isProviderSupported(string $providerType): bool
    {
        return in_array($providerType, self::getAvailableProviders());
    }

    /**
     * Get required credential fields for a provider type
     * 
     * @param string $providerType Provider type
     * @return array Array of required field names
     */
    public static function getRequiredFields(string $providerType): array
    {
        return match($providerType) {
            'stripe' => [
                'api_key' => 'Secret Key (sk_...)',
                'public_key' => 'Publishable Key (pk_...)',
                'webhook_secret' => 'Webhook Secret (whsec_...) [Optional]'
            ],
            'square' => [
                'api_key' => 'Access Token',
                'public_key' => 'Application ID',
                'location_id' => 'Location ID'
            ],
            'paypal' => [
                'api_key' => 'Client ID',
                'api_secret' => 'Client Secret'
            ],
            default => []
        };
    }

    /**
     * Get provider configuration instructions
     * 
     * @param string $providerType Provider type
     * @return array Instructions and links
     */
    public static function getSetupInstructions(string $providerType): array
    {
        return match($providerType) {
            'stripe' => [
                'title' => 'Stripe Setup',
                'steps' => [
                    '1. Go to Stripe Dashboard: https://dashboard.stripe.com',
                    '2. Navigate to Developers > API Keys',
                    '3. Copy your Secret Key (starts with sk_)',
                    '4. Copy your Publishable Key (starts with pk_)',
                    '5. (Optional) Go to Webhooks and create endpoint, copy signing secret'
                ],
                'docs_url' => 'https://stripe.com/docs/keys',
                'dashboard_url' => 'https://dashboard.stripe.com/apikeys'
            ],
            'square' => [
                'title' => 'Square Setup',
                'steps' => [
                    '1. Go to Square Developer Dashboard: https://developer.squareup.com',
                    '2. Create or select your application',
                    '3. Go to Credentials tab',
                    '4. Copy your Access Token',
                    '5. Copy your Application ID',
                    '6. Go to Locations and copy your Location ID'
                ],
                'docs_url' => 'https://developer.squareup.com/docs/build-basics/access-tokens',
                'dashboard_url' => 'https://developer.squareup.com/apps'
            ],
            'paypal' => [
                'title' => 'PayPal Setup',
                'steps' => [
                    '1. Go to PayPal Developer Dashboard: https://developer.paypal.com',
                    '2. Navigate to My Apps & Credentials',
                    '3. Create a new app or select existing one',
                    '4. Copy your Client ID',
                    '5. Click "Show" to reveal and copy Client Secret'
                ],
                'docs_url' => 'https://developer.paypal.com/api/rest/',
                'dashboard_url' => 'https://developer.paypal.com/dashboard/applications'
            ],
            default => [
                'title' => 'Unknown Provider',
                'steps' => [],
                'docs_url' => '',
                'dashboard_url' => ''
            ]
        };
    }

    /**
     * Test a provider's credentials without saving
     * 
     * @param string $providerType Provider type
     * @param array $credentials Credentials array
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public static function testCredentials(string $providerType, array $credentials): array
    {
        try {
            // Create a temporary credentials object
            $credentialsObj = (object) array_merge([
                'provider_type' => $providerType,
                'environment' => $credentials['environment'] ?? 'sandbox',
                'currency' => $credentials['currency'] ?? 'USD'
            ], $credentials);

            $provider = self::create($credentialsObj);
            $valid = $provider->validateCredentials();

            if ($valid) {
                return [
                    'success' => true,
                    'message' => 'Credentials are valid and working!',
                    'details' => [
                        'provider' => $provider->getProviderName(),
                        'environment' => $provider->getEnvironment(),
                        'currency' => $provider->getCurrency()
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Credentials validation failed. Please check your keys.',
                    'details' => []
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error testing credentials: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
}
