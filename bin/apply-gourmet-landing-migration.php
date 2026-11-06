<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;

$apply = in_array('--apply', $argv, true);
$db = new Connection();

$db->query("SHOW COLUMNS FROM event_requests LIKE 'event_time'");
$eventTime = $db->fetchOne();
if (!$eventTime) {
    throw new RuntimeException('The event_requests.event_time column was not found; no data was changed.');
}

$db->query("SHOW COLUMNS FROM store_gourmet_settings LIKE 'corporate_meal_box_price'");
$mealBoxColumn = $db->fetchOne();
$db->query("SHOW TABLES LIKE 'gourmet_lead_rate_events'");
$rateTable = $db->fetchOne();
$db->query("SHOW TABLES LIKE 'gourmet_conversion_exports'");
$exportTable = $db->fetchOne();

$pending = [];
if (strtoupper((string)$eventTime->Null) !== 'YES') {
    $pending[] = 'event_requests.event_time must allow NULL';
}
if (!$mealBoxColumn) {
    $pending[] = 'store_gourmet_settings.corporate_meal_box_price is missing';
}
if (!$rateTable) {
    $pending[] = 'gourmet_lead_rate_events is missing';
}
if (!$exportTable) {
    $pending[] = 'gourmet_conversion_exports is missing';
}

if ($pending === []) {
    echo "Gourmet landing database migrations are already current.\n";
    exit(0);
}

if (!$apply) {
    echo "Dry run; pending changes:\n- " . implode("\n- ", $pending) . "\nRun with --apply.\n";
    exit(0);
}

if (strtoupper((string)$eventTime->Null) !== 'YES') {
    $db->query('ALTER TABLE event_requests MODIFY COLUMN event_time TIME NULL');
    $db->execute();
}

$sql = (string)file_get_contents(dirname(__DIR__) . '/db/20261106_gourmet_ads_delta.sql');
$statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY);
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $db->query($statement);
    $db->execute();
}

echo "Gourmet landing database migrations applied successfully.\n";
