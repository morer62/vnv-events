<?php

namespace App\Services\Delivery;

class ManualDeliveryProvider implements DeliveryProvider
{
    public function __construct(private readonly string $code = 'manual') {}

    public function getCode(): string { return $this->code; }

    public function getQuote(array $pickup, array $destination, array $package = []): array
    {
        return [
            'success' => false,
            'provider' => $this->code,
            'provider_quote_id' => null,
            'provider_cost' => 0.0,
            'currency' => 'USD',
            'distance_miles' => isset($destination['distance_miles']) ? (float)$destination['distance_miles'] : null,
            'eta_minutes' => null,
            'expires_at' => null,
            'error' => 'Live delivery quoting is not configured for this provider.',
        ];
    }

    public function createDelivery(array $quote, array $order): array
    {
        return ['success' => true, 'provider' => $this->code, 'reference' => null, 'status' => 'MANUAL_ASSIGNMENT_REQUIRED'];
    }

    public function getDelivery(string $providerReference): array
    {
        return ['success' => true, 'provider' => $this->code, 'reference' => $providerReference, 'status' => 'MANUAL'];
    }

    public function cancelDelivery(string $providerReference): array
    {
        return ['success' => true, 'provider' => $this->code, 'reference' => $providerReference, 'status' => 'CANCELLED'];
    }
}
