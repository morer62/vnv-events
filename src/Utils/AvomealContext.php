<?php

namespace App\Utils;

use App\Repositories\BrandSiteSettingsRepository;

class AvomealContext
{
    public const DEFAULT_OWNER_ID = 2;
    public const DEFAULT_MINIMUM_ORDER_AMOUNT = 65.00;

    public static function ownerId(): int
    {
        return SiteContext::businessOwnerId();
    }

    public static function minimumOrderAmount(): float
    {
        try {
            $configuredSetting = (new BrandSiteSettingsRepository())->get('minimum_order_amount');
            $settingAmount = (float)$configuredSetting;
            if ($settingAmount > 0) {
                return $settingAmount;
            }
        } catch (\Throwable $e) {
            // Keep the public store resilient before brand_site_settings exists.
        }

        $configured = $_ENV['STORE_MINIMUM_ORDER_AMOUNT']
            ?? $_ENV['AVOMEAL_MINIMUM_ORDER_AMOUNT']
            ?? null;

        $amount = (float)$configured;

        return $amount > 0 ? $amount : self::DEFAULT_MINIMUM_ORDER_AMOUNT;
    }

    public static function brandName(): string
    {
        return SiteContext::siteName();
    }

    public static function siteKey(): string
    {
        return SiteContext::siteKey();
    }
}
