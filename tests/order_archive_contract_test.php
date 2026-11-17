<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\ArchivedOrderService;

function archiveContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/src/Repositories/OrdersRepository.php');
$archivedController = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/archived/index.php');
$archivedTemplate = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/archived/index.twig');
$executionController = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/execution/index.php');
$executionTemplate = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/execution/index.twig');
$ordersTemplate = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/index.twig');
$editController = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/edit/index.php');
$editTemplate = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/orders/edit/index.twig');
$weeklyExecution = file_get_contents($root . '/src/Services/WeeklyExecutionService.php');
$migration = file_get_contents($root . '/db/migrations/20261117160000_add_archived_at_to_orders.php');

archiveContractAssert(ArchivedOrderService::confirmationCode(1199) === 'DELETE VNV3411199', 'Permanent-delete confirmation must identify the exact order.');
archiveContractAssert(str_contains($repository, 'o.id_owner = :owner_id'), 'Archived orders must be tenant scoped by owner.');
archiveContractAssert(str_contains($repository, 'u.email LIKE :search_email'), 'Archived order search must support client email.');
archiveContractAssert(str_contains($repository, 'FROM orders_payments p'), 'Archived orders must summarize payments.');
archiveContractAssert(str_contains($repository, 'FROM orders_advances a'), 'Archived orders must summarize advances/deposits.');
archiveContractAssert(str_contains($archivedController, "\$action === 'delete_permanently'"), 'Archived orders must expose the guarded permanent-delete action.');
archiveContractAssert(str_contains($archivedTemplate, 'Client email, name, phone, address or order number'), 'Archived order UI must explain its email search.');
archiveContractAssert(str_contains($archivedTemplate, 'Payments / deposits'), 'Archived order UI must link to payment/deposit records.');
archiveContractAssert(str_contains($executionController, "\$action === 'archive_order'"), 'Weekly execution must accept the archive action.');
archiveContractAssert(str_contains($executionTemplate, 'Archive order'), 'Weekly execution must show an archive control.');
archiveContractAssert(str_contains($ordersTemplate, 'Archive estimate'), 'The complete estimates table must expose archive next to the visible estimate ID.');
archiveContractAssert(str_contains($ordersTemplate, 'Archive order'), 'The complete orders table must expose archive next to the visible order ID.');
archiveContractAssert(str_contains($editController, "\$_POST['action'] ?? '') === 'archive_order'"), 'The order editor must accept the archive action.');
archiveContractAssert(str_contains($editTemplate, 'Remove it from active calendars and execution'), 'The order editor must explain and expose archive behavior.');
archiveContractAssert(str_contains($weeklyExecution, "'is_archived' => 0"), 'Archived orders must remain excluded from weekly execution.');
archiveContractAssert(str_contains($migration, "'archived_at'"), 'Archive timestamp migration is required.');

echo "Order archive contract: OK\n";
