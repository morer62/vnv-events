<?php

namespace App\Services\Delivery;

use App\Repositories\Connection;

final class DeliveryPricingService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    public function settings(int $ownerId, string $siteKey): array
    {
        $this->db->query('SELECT * FROM store_gourmet_settings WHERE id_owner=:owner AND site_key=:site LIMIT 1');
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':site', $siteKey);
        $row = $this->db->fetchOne();
        return $row ? (array)$row : [];
    }

    public function priceQuote(array $providerQuote, int $ownerId, string $siteKey): array
    {
        $settings = $this->settings($ownerId, $siteKey);
        $providerCost = max(0.0, (float)($providerQuote['provider_cost'] ?? 0));
        $distance = isset($providerQuote['distance_miles']) ? (float)$providerQuote['distance_miles'] : null;

        if (!empty($providerQuote['success']) && $providerCost > 0) {
            $markup = max(0.0, (float)($settings['delivery_markup_percent'] ?? 10));
            $increment = max(0.01, (float)($settings['delivery_rounding_increment'] ?? 1));
            $rawFee = $providerCost * (1 + $markup / 100);
            $customerFee = ceil($rawFee / $increment) * $increment;
        } else {
            $customerFee = $this->fallbackFee($distance, $settings['fallback_delivery_tiers_json'] ?? '[]');
        }

        return [
            'provider_cost' => round($providerCost, 2),
            'customer_fee' => round($customerFee, 2),
            'delivery_margin' => round($customerFee - $providerCost, 2),
            'currency' => strtoupper((string)($providerQuote['currency'] ?? 'USD')),
            'distance_miles' => $distance,
        ];
    }

    private function fallbackFee(?float $distance, string $json): float
    {
        if ($distance === null || $distance < 0) {
            throw new \InvalidArgumentException('A delivery distance is required when live quoting is unavailable.');
        }
        $tiers = json_decode($json, true);
        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            if ($distance >= (float)$tier['min_miles'] && $distance <= (float)$tier['max_miles']) {
                return (float)$tier['fee'];
            }
        }
        throw new \DomainException('The destination is outside the configured delivery range.');
    }
}
