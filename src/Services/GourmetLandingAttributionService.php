<?php

namespace App\Services;

final class GourmetLandingAttributionService
{
    public const KEYS = [
        'gclid', 'gbraid', 'wbraid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    public static function sanitize(array $input): array
    {
        $result = [];
        foreach (self::KEYS as $key) {
            $value = trim((string)($input[$key] ?? ''));
            $result[$key] = mb_substr(preg_replace('/[^A-Za-z0-9._~+\-\s]/u', '', $value), 0, 255);
        }
        return $result;
    }

    public static function guestBucketMinimum(string $bucket): int
    {
        return match ($bucket) {
            '25-40' => 25,
            '41-75' => 41,
            '76-150' => 76,
            '150+' => 150,
            'fewer-than-25' => 1,
            default => 0,
        };
    }

    public static function isSouthFloridaZip(string $zip): bool
    {
        if (!preg_match('/^\d{5}$/', $zip)) {
            return false;
        }
        return in_array(substr($zip, 0, 3), ['330', '331', '333', '334'], true);
    }

    public static function isQualified(
        string $eventDate,
        string $guestBucket,
        int $minimumGuests,
        string $zip,
        string $email,
        string $phone
    ): bool {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        $today = new \DateTimeImmutable('today');
        $contactValid = filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen(preg_replace('/\D+/', '', $phone)) >= 10;

        return $date instanceof \DateTimeImmutable
            && $date >= $today
            && self::guestBucketMinimum($guestBucket) >= $minimumGuests
            && self::isSouthFloridaZip($zip)
            && (bool)$contactValid;
    }

    public static function safeLandingPath(string $landing): string
    {
        $path = (string)(parse_url($landing, PHP_URL_PATH) ?? '');
        $path = '/' . ltrim($path, '/');
        return preg_match('#^/[A-Za-z0-9/_-]+/?$#', $path) ? $path : '/vnv-gourmet/';
    }
}
