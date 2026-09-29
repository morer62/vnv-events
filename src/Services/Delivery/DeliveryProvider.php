<?php

namespace App\Services\Delivery;

interface DeliveryProvider
{
    public function getCode(): string;

    /** @return array{success:bool,provider:string,provider_quote_id:?string,provider_cost:float,currency:string,distance_miles:?float,eta_minutes:?int,expires_at:?string,error:?string} */
    public function getQuote(array $pickup, array $destination, array $package = []): array;

    public function createDelivery(array $quote, array $order): array;

    public function getDelivery(string $providerReference): array;

    public function cancelDelivery(string $providerReference): array;
}
