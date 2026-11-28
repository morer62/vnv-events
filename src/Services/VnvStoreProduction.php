<?php

namespace App\Services;

final class VnvStoreProduction
{
    public const CATEGORY = 'event-planners-and-coordinator';
    public const PROPOSALS = [
        'production-essential', 'full-event-production', 'full-event-production-plus',
        'advanced-production', 'brand-activation',
    ];
    public const LEGACY_PLANNER_SLUG = 'event-planners-premium-packag';

    public static function packagePrices(string $siteKey): array
    {
        if ($siteKey !== 'vnvevents') return [];
        $services = VnvProductionCatalog::services();
        $prices = [];
        foreach (VnvProductionCatalog::PACKAGE_SLUGS as $service => $slug) {
            $prices[$slug] = reset($services[$service]['tiers']);
        }
        return $prices;
    }
}
