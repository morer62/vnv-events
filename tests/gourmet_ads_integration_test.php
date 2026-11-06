<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;
use App\Services\GourmetLeadRateLimiter;
use App\Services\GourmetOfflineConversionExporter;
use App\Utils\SiteContext;

$ownerId = SiteContext::businessOwnerId();
$siteKey = SiteContext::siteKey();
$testIp = '192.0.2.44';
$ipHash = GourmetLeadRateLimiter::hashIp($testIp);
$db = new Connection();

$cleanup = static function () use ($db, $ownerId, $siteKey, $ipHash): void {
    $db->query('DELETE FROM gourmet_lead_rate_events WHERE id_owner=:owner AND site_key=:site AND ip_hash=:hash');
    $db->bind(':owner', $ownerId);
    $db->bind(':site', $siteKey);
    $db->bind(':hash', $ipHash);
    $db->execute();
};

$cleanup();
try {
    $limiter = new GourmetLeadRateLimiter();
    $decisions = [];
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $decisions[] = $limiter->consume($ownerId, $siteKey, $testIp);
    }
    if (!$decisions[4]['allowed'] || $decisions[5]['allowed'] || $decisions[5]['reason'] !== 'ten_minute_limit') {
        throw new RuntimeException('Shared IP limiter did not block the sixth cross-session request.');
    }

    $db->query('SELECT COUNT(*) total FROM gourmet_conversion_exports WHERE id_owner=:owner');
    $db->bind(':owner', $ownerId);
    $before = (int)$db->fetchOne()->total;
    (new GourmetOfflineConversionExporter())->pending($ownerId);
    $db->query('SELECT COUNT(*) total FROM gourmet_conversion_exports WHERE id_owner=:owner');
    $db->bind(':owner', $ownerId);
    $after = (int)$db->fetchOne()->total;
    if ($before !== $after) {
        throw new RuntimeException('Dry-run candidate collection marked conversion rows.');
    }

    echo "Gourmet Ads integration OK: sixth request blocked; dry-run export count unchanged\n";
} finally {
    $cleanup();
}
