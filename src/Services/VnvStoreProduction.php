<?php

namespace App\Services;

final class VnvStoreProduction
{
    public const CATEGORY = 'event-planners-and-coordinator';
    public const PROPOSALS = ['production-essential', 'full-event-production', 'brand-activation'];
    public const PACKAGES = [
        'event-planners-premium-packag' => 'event-planner',
        'day-coordinator-service-packages-premium' => 'day-coordinator',
    ];

    public static function packagePrices(string $siteKey): array
    {
        if ($siteKey !== 'vnvevents') return [];
        $services = VnvProductionCatalog::services();
        $prices = [];
        foreach (self::PACKAGES as $slug => $service) {
            $prices[$slug] = $services[$service]['minimum'];
        }
        return $prices;
    }
}
