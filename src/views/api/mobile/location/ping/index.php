<?php

use App\Repositories\Connection;
use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

function mobileLocationBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);

    return is_array($json) ? array_merge($_POST ?: [], $json) : ($_POST ?: []);
}

function mobileLocationTableExists(Connection $db, string $table): bool
{
    try {
        $db->query("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            LIMIT 1
        ");
        $db->bind(':table', $table);
        return (bool)$db->fetchOne();
    } catch (Throwable $e) {
        return false;
    }
}

function mobileLocationColumnExists(Connection $db, string $table, string $column): bool
{
    try {
        $db->query("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column
            LIMIT 1
        ");
        $db->bind(':table', $table);
        $db->bind(':column', $column);
        return (bool)$db->fetchOne();
    } catch (Throwable $e) {
        return false;
    }
}

function mobileLocationInsertIfPossible(Connection $db, string $table, array $data): bool
{
    if (!mobileLocationTableExists($db, $table)) {
        return false;
    }

    $columns = [];
    $params = [];
    foreach ($data as $column => $value) {
        if (mobileLocationColumnExists($db, $table, $column)) {
            $columns[] = $column;
            $params[":{$column}"] = $value;
        }
    }

    if (!$columns) {
        return false;
    }

    $db->query("
        INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`)
        VALUES (" . implode(', ', array_keys($params)) . ")
    ");
    foreach ($params as $param => $value) {
        $db->bind($param, $value);
    }
    $db->execute();

    return true;
}

function mobileLocationUpdateOpenPayrollHour(Connection $db, int $userId, ?int $ownerId, float $lat, float $lng, ?float $accuracy, string $source, string $permission): bool
{
    if (!mobileLocationTableExists($db, 'payroll_hours')) {
        return false;
    }

    $fields = [];
    $bindings = [];

    foreach ([
        'location_lat' => $lat,
        'location_long' => $lng,
        'location_accuracy' => $accuracy,
        'location_source' => $source,
        'location_permission_status' => $permission,
    ] as $column => $value) {
        if (mobileLocationColumnExists($db, 'payroll_hours', $column)) {
            $fields[] = "`{$column}` = :{$column}";
            $bindings[":{$column}"] = $value;
        }
    }

    if (!$fields) {
        return false;
    }

    $ownerSql = $ownerId ? " AND (`id_owner` = :owner_id OR `id_owner` IS NULL)" : '';
    $db->query("
        UPDATE `payroll_hours`
        SET " . implode(', ', $fields) . "
        WHERE `id_user` = :user_id
          AND `end_time` IS NULL
          {$ownerSql}
        ORDER BY `start_time` DESC, `id` DESC
        LIMIT 1
    ");

    foreach ($bindings as $param => $value) {
        $db->bind($param, $value);
    }
    $db->bind(':user_id', $userId);
    if ($ownerId) {
        $db->bind(':owner_id', $ownerId);
    }
    $db->execute();

    return $db->rowCount() > 0;
}

$router->post(function () {
    $payload = mobileLocationBody();
    $user = ApiAuthService::getAuthenticatedUser(null, $payload);

    if (!$user) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Authentication required.',
        ], 401);
    }

    $userId = (int)($payload['user_id'] ?? $user->getId());
    if ($userId !== (int)$user->getId() && (int)$user->getLevel() !== 1) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Cannot submit location for another user.',
        ], 403);
    }

    $lat = isset($payload['latitude']) ? (float)$payload['latitude'] : (isset($payload['lat']) ? (float)$payload['lat'] : null);
    $lng = isset($payload['longitude']) ? (float)$payload['longitude'] : (isset($payload['lng']) ? (float)$payload['lng'] : null);
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Invalid location payload.',
        ], 422);
    }

    $ownerId = (int)($payload['id_owner'] ?? $payload['id_user_business'] ?? $payload['business_id'] ?? $user->getOwner());
    $orderId = (int)($payload['id_store_order'] ?? 0);
    $taskId = (int)($payload['id_store_order_task'] ?? 0);
    $accuracy = isset($payload['accuracy']) && $payload['accuracy'] !== '' ? (float)$payload['accuracy'] : null;
    $platform = trim((string)($payload['platform'] ?? 'react_native'));
    $source = trim((string)($payload['source'] ?? 'mobile_webview'));
    $permission = trim((string)($payload['permission_status'] ?? 'unknown'));
    $deviceId = trim((string)($payload['device_id'] ?? ''));
    $context = trim((string)($payload['context'] ?? 'payroll_clock'));
    $eventType = strtoupper(trim((string)($payload['event_type'] ?? 'LOCATION_UPDATE')));

    $allowedEvents = ['TASK_START', 'LOCATION_UPDATE', 'OUT_FOR_DELIVERY', 'ARRIVED', 'DELIVERED', 'CLOCK_IN', 'CLOCK_OUT'];
    if (!in_array($eventType, $allowedEvents, true)) {
        $eventType = 'LOCATION_UPDATE';
    }

    $db = new Connection();
    $payrollUpdated = mobileLocationUpdateOpenPayrollHour(
        $db,
        $userId,
        $ownerId > 0 ? $ownerId : null,
        $lat,
        $lng,
        $accuracy,
        $source,
        $permission
    );

    $deliveryStored = false;
    if ($orderId > 0 && $ownerId > 0) {
        $deliveryStored = mobileLocationInsertIfPossible($db, 'store_delivery_location_logs', [
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'id_store_order_task' => $taskId > 0 ? $taskId : null,
            'id_user' => $userId,
            'event_type' => $eventType,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $accuracy,
            'platform' => $platform,
            'source' => $source,
            'permission_status' => $permission,
            'device_id' => $deviceId !== '' ? $deviceId : null,
            'context' => $context,
            'recorded_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (mobileLocationTableExists($db, 'store_order_workflow')) {
            $workflowFields = [];
            if (mobileLocationColumnExists($db, 'store_order_workflow', 'delivery_lat')) {
                $workflowFields[] = 'delivery_lat = :lat';
            }
            if (mobileLocationColumnExists($db, 'store_order_workflow', 'delivery_lng')) {
                $workflowFields[] = 'delivery_lng = :lng';
            }
            if (mobileLocationColumnExists($db, 'store_order_workflow', 'delivery_location_at')) {
                $workflowFields[] = 'delivery_location_at = NOW()';
            }
            if (mobileLocationColumnExists($db, 'store_order_workflow', 'updated_at')) {
                $workflowFields[] = 'updated_at = NOW()';
            }
        }

        if (!empty($workflowFields)) {
            $db->query("
                UPDATE `store_order_workflow`
                SET " . implode(', ', $workflowFields) . "
                WHERE id_store_order = :order_id
                  AND id_owner = :owner_id
                LIMIT 1
            ");
            if (in_array('delivery_lat = :lat', $workflowFields, true)) {
                $db->bind(':lat', $lat);
            }
            if (in_array('delivery_lng = :lng', $workflowFields, true)) {
                $db->bind(':lng', $lng);
            }
            $db->bind(':order_id', $orderId);
            $db->bind(':owner_id', $ownerId);
            $db->execute();
        }
    }

    return JsonResponse::createResponse([
        'success' => true,
        'updated_payroll_location' => $payrollUpdated,
        'stored_delivery_location' => $deliveryStored,
        'context' => $context,
        'event_type' => $eventType,
        'server_time' => date('c'),
    ]);
});

$router->run();
