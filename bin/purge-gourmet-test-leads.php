<?php

declare(strict_types=1);

use App\Repositories\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$apply = in_array('--apply', $argv, true);
$ownerId = 2;
$olderThanDays = 0;
$confirmation = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--owner=')) {
        $ownerId = max(1, (int)substr($argument, 8));
    } elseif (str_starts_with($argument, '--older-than-days=')) {
        $olderThanDays = max(0, (int)substr($argument, 18));
    } elseif (str_starts_with($argument, '--confirm=')) {
        $confirmation = substr($argument, 10);
    }
}

if ($apply && $confirmation !== 'PURGE-GOURMET-TEST-LEADS') {
    fwrite(STDERR, "--apply requires --confirm=PURGE-GOURMET-TEST-LEADS\n");
    exit(2);
}

$db = new Connection();
$sql = "SELECT id,source,payload_json,created_at FROM lead_intake
        WHERE id_owner=:owner
          AND source IN ('gourmet_landing','gourmet_whatsapp_click')
          AND status='TEST'";
if ($olderThanDays > 0) {
    $sql .= ' AND created_at < UTC_TIMESTAMP() - INTERVAL ' . $olderThanDays . ' DAY';
}
$sql .= ' ORDER BY id';
$db->query($sql);
$db->bind(':owner', $ownerId);

$leadIds = [];
$eventRequestIds = [];
foreach ($db->fetchAll() as $row) {
    $payload = json_decode((string)$row->payload_json, true);
    if (!is_array($payload) || empty($payload['is_test'])) {
        continue;
    }
    $leadIds[] = (int)$row->id;
    if (!empty($payload['event_request_id'])) {
        $eventRequestIds[] = (int)$payload['event_request_id'];
    }
}

echo sprintf(
    "%s: %d verified test lead(s), %d linked event request(s).\n",
    $apply ? 'APPLY' : 'DRY RUN',
    count($leadIds),
    count(array_unique($eventRequestIds))
);

if (!$apply || $leadIds === []) {
    exit(0);
}

$deleteIds = static function (Connection $connection, string $table, string $ownerColumn, int $owner, array $ids): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return;
    }
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $placeholders[] = ':id' . $index;
    }
    $connection->query("DELETE FROM {$table} WHERE {$ownerColumn}=:owner AND id IN (" . implode(',', $placeholders) . ')');
    $connection->bind(':owner', $owner);
    foreach ($ids as $index => $id) {
        $connection->bind(':id' . $index, $id);
    }
    $connection->execute();
};

try {
    $db->beginTransaction();
    $deleteIds($db, 'lead_intake', 'id_owner', $ownerId, $leadIds);
    $deleteIds($db, 'event_requests', 'id_owner', $ownerId, $eventRequestIds);
    $db->commit();
    echo "Verified Gourmet QA records were deleted.\n";
} catch (Throwable $e) {
    try {
        $db->rollback();
    } catch (Throwable) {
    }
    fwrite(STDERR, 'Purge failed and was rolled back: ' . $e->getMessage() . "\n");
    exit(3);
}
