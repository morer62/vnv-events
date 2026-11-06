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
$column = $db->fetchOne();

if (!$column) {
    throw new RuntimeException('The event_requests.event_time column was not found; no data was changed.');
}

if (strtoupper((string) $column->Null) === 'YES') {
    echo "Gourmet landing database migration is already current.\n";
    exit(0);
}

if (!$apply) {
    echo "Dry run: event_requests.event_time must allow NULL. Run with --apply.\n";
    exit(0);
}

$db->query('ALTER TABLE event_requests MODIFY COLUMN event_time TIME NULL');
$db->execute();

echo "Gourmet landing database migration applied successfully.\n";
