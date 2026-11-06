<?php

namespace App\Services;

use App\Repositories\Connection;
use Throwable;

final class GourmetLeadRateLimiter
{
    public const TEN_MINUTE_LIMIT = 5;
    public const DAILY_LIMIT = 20;

    /** @return array{allowed:bool,reason:string,ten_minute_count:int,daily_count:int} */
    public function consume(int $ownerId, string $siteKey, ?string $ip = null): array
    {
        $ip = $ip ?? self::resolveClientIp($_SERVER);
        $ipHash = self::hashIp($ip);
        $lockName = 'gourmet-rate-' . substr(hash('sha256', $ownerId . '|' . $siteKey . '|' . $ipHash), 0, 40);
        $db = new Connection();
        $lockAcquired = false;

        try {
            $db->query('SELECT GET_LOCK(:lock_name, 2) acquired');
            $db->bind(':lock_name', $lockName);
            $lockAcquired = (int)($db->fetchOne()->acquired ?? 0) === 1;
            if (!$lockAcquired) {
                return ['allowed' => true, 'reason' => 'lock_unavailable', 'ten_minute_count' => 0, 'daily_count' => 0];
            }

            $db->query("SELECT
                SUM(CASE WHEN is_allowed=1 AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE THEN 1 ELSE 0 END) ten_minute_count,
                SUM(CASE WHEN is_allowed=1 AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY THEN 1 ELSE 0 END) daily_count
                FROM gourmet_lead_rate_events
                WHERE id_owner=:owner AND site_key=:site AND ip_hash=:ip_hash
                  AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
            $db->bind(':owner', $ownerId);
            $db->bind(':site', $siteKey);
            $db->bind(':ip_hash', $ipHash);
            $counts = $db->fetchOne();
            $tenMinuteCount = (int)($counts->ten_minute_count ?? 0);
            $dailyCount = (int)($counts->daily_count ?? 0);
            $decision = self::decision($tenMinuteCount, $dailyCount);

            $db->query('INSERT INTO gourmet_lead_rate_events (id_owner,site_key,ip_hash,is_allowed,reason_code,created_at) VALUES (:owner,:site,:ip_hash,:allowed,:reason,UTC_TIMESTAMP())');
            $db->bind(':owner', $ownerId);
            $db->bind(':site', $siteKey);
            $db->bind(':ip_hash', $ipHash);
            $db->bind(':allowed', $decision['allowed'] ? 1 : 0);
            $db->bind(':reason', $decision['reason']);
            $db->execute();

            if (random_int(1, 100) === 1) {
                $db->query('DELETE FROM gourmet_lead_rate_events WHERE created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY');
                $db->execute();
            }

            return $decision + ['ten_minute_count' => $tenMinuteCount, 'daily_count' => $dailyCount];
        } catch (Throwable $e) {
            error_log('Gourmet shared rate limiter unavailable: ' . $e->getMessage());
            return ['allowed' => true, 'reason' => 'storage_unavailable', 'ten_minute_count' => 0, 'daily_count' => 0];
        } finally {
            if ($lockAcquired) {
                try {
                    $db->query('SELECT RELEASE_LOCK(:lock_name)');
                    $db->bind(':lock_name', $lockName);
                    $db->fetchOne();
                } catch (Throwable $releaseError) {
                    error_log('Gourmet rate-limit lock release failed: ' . $releaseError->getMessage());
                }
            }
        }
    }

    /** @return array{allowed:bool,reason:string} */
    public static function decision(int $tenMinuteCount, int $dailyCount): array
    {
        if ($dailyCount >= self::DAILY_LIMIT) {
            return ['allowed' => false, 'reason' => 'daily_limit'];
        }
        if ($tenMinuteCount >= self::TEN_MINUTE_LIMIT) {
            return ['allowed' => false, 'reason' => 'ten_minute_limit'];
        }
        return ['allowed' => true, 'reason' => 'allowed'];
    }

    public static function resolveClientIp(array $server): string
    {
        $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        $candidates = [];
        if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = trim((string)$server['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', (string)$server['HTTP_X_FORWARDED_FOR']) as $forwarded) {
                $candidates[] = trim($forwarded);
            }
        }
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !self::isTrustedProxy($candidate)) {
                return $candidate;
            }
        }
        return $remote;
    }

    public static function hashIp(string $ip): string
    {
        $secret = (string)($_ENV['GOURMET_RATE_LIMIT_SECRET'] ?? $_ENV['APP_SECRET'] ?? 'vnv-gourmet-rate-v1');
        return hash_hmac('sha256', $ip, $secret);
    }

    private static function isTrustedProxy(string $ip): bool
    {
        $configured = trim((string)($_ENV['TRUSTED_PROXIES'] ?? $_ENV['GOURMET_TRUSTED_PROXIES'] ?? ''));
        if ($configured === '') {
            return false;
        }
        foreach (preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) as $range) {
            if (self::ipMatches($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function ipMatches(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return hash_equals($range, $ip);
        }
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, '');
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }
        $prefix = filter_var($prefix, FILTER_VALIDATE_INT);
        $maxBits = strlen($ipBinary) * 8;
        if ($prefix === false || $prefix < 0 || $prefix > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
