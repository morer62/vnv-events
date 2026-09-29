<?php

namespace App\Services\Delivery;

/** A deliberately quote-only contract. Implementations cannot dispatch deliveries. */
interface QuoteReferenceProvider
{
    public function getCode(): string;

    /** @return array{success:bool,provider:string,provider_quote_id:?string,provider_cost:float,currency:string,eta_minutes:?int,expires_at:?string,raw_response:?string,error:?string} */
    public function getQuote(array $pickup, array $destination, array $context = []): array;
}
