<?php

require dirname(__DIR__).'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;
use App\Services\OrderContractPolicyService;

$options = getopt('', ['owner::', 'date::', 'apply']);
$ownerId = max(1, (int) ($options['owner'] ?? 2));
$timezone = new DateTimeZone('America/New_York');
$date = (string) ($options['date'] ?? (new DateTimeImmutable('yesterday', $timezone))->format('Y-m-d'));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD.\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
$db = new Connection();
$contractId = (new OrderContractPolicyService($ownerId, $db))->requiredGeneralConditionsId();
$db->query(
    "SELECT id
     FROM orders
     WHERE id_owner = :owner
       AND id_contract IS NULL
       AND COALESCE(is_archived, 0) = 0
       AND COALESCE(status_workflow, '') LIKE 'INVOICE_%'
       AND DATE(created_at) = :created_date
     ORDER BY id"
);
$db->bind(':owner', $ownerId);
$db->bind(':created_date', $date);
$ids = array_map(static fn ($row) => (int) $row->id, $db->fetchAll());

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'owner_id' => $ownerId,
    'created_date' => $date,
    'general_conditions_contract_id' => $contractId,
    'candidate_count' => count($ids),
    'order_ids' => $ids,
    'updated_count' => 0,
];

if ($apply && $ids) {
    $db->beginTransaction();
    try {
        $db->query(
            "UPDATE orders
             SET id_contract = :contract
             WHERE id_owner = :owner
               AND id_contract IS NULL
               AND COALESCE(is_archived, 0) = 0
               AND COALESCE(status_workflow, '') LIKE 'INVOICE_%'
               AND DATE(created_at) = :created_date"
        );
        $db->bind(':contract', $contractId);
        $db->bind(':owner', $ownerId);
        $db->bind(':created_date', $date);
        $db->execute();
        $result['updated_count'] = $db->rowCount();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
