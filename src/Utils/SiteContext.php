<?php

namespace App\Utils;

class SiteContext
{
    public const DEFAULT_SITE_KEY = 'avomeal';
    public const DEFAULT_SITE_NAME = 'Avomeal';
    public const DEFAULT_PUBLIC_BASE_URL = 'https://avomeal.com';
    public const DEFAULT_BUSINESS_OWNER_ID = 2;

    public static function siteKey(): string
    {
        $siteKey = trim((string)($_ENV['SITE_KEY'] ?? $_ENV['AVOMEAL_SITE_KEY'] ?? ''));

        return $siteKey !== '' ? strtolower($siteKey) : self::DEFAULT_SITE_KEY;
    }

    public static function siteName(): string
    {
        $siteName = trim((string)($_ENV['SITE_NAME'] ?? $_ENV['AVOMEAL_BRAND_NAME'] ?? ''));

        return $siteName !== '' ? $siteName : self::DEFAULT_SITE_NAME;
    }

    public static function publicBaseUrl(): string
    {
        $baseUrl = trim((string)($_ENV['SITE_PUBLIC_BASE_URL'] ?? $_ENV['APP_URL'] ?? ''));

        return rtrim($baseUrl !== '' ? $baseUrl : self::DEFAULT_PUBLIC_BASE_URL, '/');
    }

    public static function businessOwnerId(): int
    {
        $candidates = [
            $_ENV['SITE_BUSINESS_USER_ID'] ?? null,
            $_ENV['AVOMEAL_BUSINESS_USER_ID'] ?? null,
            $_ENV['STORE_OWNER_ID'] ?? null,
            $_ENV['VNV_EVENTS_OWNER_ID'] ?? null,
            $_ENV['DEFAULT_OWNER_ID'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $ownerId = (int)$candidate;
            if ($ownerId > 0) {
                return $ownerId;
            }
        }

        return self::DEFAULT_BUSINESS_OWNER_ID;
    }

    public static function sharedSiteKeys(): array
    {
        return ['shared', 'global', 'all_sites'];
    }
}
