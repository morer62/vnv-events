<?php

namespace App\Services;

final class GourmetLandingAttributionService
{
    public const KEYS = [
        'gclid', 'gbraid', 'wbraid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    public const EVENT_TIMEZONE = 'America/New_York';

    /**
     * Dominant-county mapping derived from the U.S. Census Bureau 2020
     * ZCTA-to-County relationship file (dominant AREALAND_PART).
     * Source: https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt
     * FIPS: Broward 12011, Miami-Dade 12086, Palm Beach 12099.
     *
     * USPS ZIPs and Census ZCTAs are not identical. A ZIP absent from this
     * reviewed table is therefore UNKNOWN rather than automatically outside.
     */
    private const SERVICE_AREA_ZIPS = [
        'Broward County' => [
            '33004','33009','33019','33020','33021','33022','33023','33024','33025','33026','33027','33028','33029',
            '33060','33062','33063','33064','33065','33066','33067','33068','33069','33071','33073','33076','33301',
            '33304','33305','33306','33308','33309','33311','33312','33313','33314','33315','33316','33317','33319',
            '33321','33322','33323','33324','33325','33326','33327','33328','33330','33331','33332','33334','33351',
            '33388','33441','33442',
        ],
        'Miami-Dade County' => [
            '33010','33012','33013','33014','33015','33016','33018','33030','33031','33032','33033','33034','33035',
            '33039','33054','33055','33056','33101','33109','33122','33125','33126','33127','33128','33129','33130',
            '33131','33132','33133','33134','33135','33136','33137','33138','33139','33140','33141','33142','33143',
            '33144','33145','33146','33147','33149','33150','33154','33155','33156','33157','33158','33160','33161',
            '33162','33165','33166','33167','33168','33169','33170','33172','33173','33174','33175','33176','33177',
            '33178','33179','33180','33181','33182','33183','33184','33185','33186','33187','33189','33190','33193',
            '33194','33196',
        ],
        'Palm Beach County' => [
            '33401','33403','33404','33405','33406','33407','33408','33409','33410','33411','33412','33413','33414',
            '33415','33417','33418','33426','33428','33430','33431','33432','33433','33434','33435','33436','33437',
            '33444','33445','33446','33449','33458','33460','33461','33462','33463','33467','33470','33472','33473',
            '33476','33477','33478','33480','33483','33484','33486','33487','33493','33496','33498',
        ],
    ];

    /** Explicit external controls required by the campaign QA contract. */
    private const VERIFIED_EXTERNAL_ZIPS = [
        '33040' => 'Monroe County',
        '33455' => 'Martin County',
        '33440' => 'Hendry County',
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

    /** @return array{in_area:bool|null,status:string,county:?string,zip:string} */
    public static function classifyZip(string $zip): array
    {
        $zip = trim($zip);
        if (!preg_match('/^\d{5}$/', $zip)) {
            return ['in_area' => null, 'status' => 'unknown', 'county' => null, 'zip' => $zip];
        }

        foreach (self::SERVICE_AREA_ZIPS as $county => $zips) {
            if (in_array($zip, $zips, true)) {
                return ['in_area' => true, 'status' => 'true', 'county' => $county, 'zip' => $zip];
            }
        }

        if (isset(self::VERIFIED_EXTERNAL_ZIPS[$zip])) {
            return [
                'in_area' => false,
                'status' => 'false',
                'county' => self::VERIFIED_EXTERNAL_ZIPS[$zip],
                'zip' => $zip,
            ];
        }

        return ['in_area' => null, 'status' => 'unknown', 'county' => null, 'zip' => $zip];
    }

    public static function isSouthFloridaZip(string $zip): bool
    {
        return self::classifyZip($zip)['in_area'] === true;
    }

    public static function eventTimezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::EVENT_TIMEZONE);
    }

    public static function minimumEventDate(?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now', self::eventTimezone()))->setTimezone(self::eventTimezone());
        return $now->modify('tomorrow')->format('Y-m-d');
    }

    public static function isFutureEventDate(string $eventDate, ?\DateTimeImmutable $now = null): bool
    {
        $timezone = self::eventTimezone();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }
        $today = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
        return $date > $today;
    }

    public static function isQualified(
        string $eventDate,
        string $guestBucket,
        int $minimumGuests,
        string $zip,
        string $email,
        string $phone
    ): bool {
        $contactValid = filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen(preg_replace('/\D+/', '', $phone)) >= 10;

        return self::isFutureEventDate($eventDate)
            && self::guestBucketMinimum($guestBucket) >= $minimumGuests
            && self::isSouthFloridaZip($zip)
            && (bool)$contactValid;
    }

    public static function conversionTime(?\DateTimeImmutable $time = null): string
    {
        $time = ($time ?? new \DateTimeImmutable('now', self::eventTimezone()))->setTimezone(self::eventTimezone());
        return $time->format('Y-m-d H:i:sP');
    }

    public static function safeLandingPath(string $landing): string
    {
        $host = strtolower((string)(parse_url($landing, PHP_URL_HOST) ?? ''));
        if ($host !== '' && !in_array($host, ['vnvevents.com', 'www.vnvevents.com'], true)) {
            return '/vnv-gourmet/';
        }
        $path = (string)(parse_url($landing, PHP_URL_PATH) ?? '');
        $path = '/' . ltrim($path, '/');
        return preg_match('#^/[A-Za-z0-9/_-]+/?$#', $path) ? $path : '/vnv-gourmet/';
    }
}
