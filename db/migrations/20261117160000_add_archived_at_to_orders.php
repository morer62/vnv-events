<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddArchivedAtToOrders extends AbstractMigration
{
    public function up(): void
    {
        $orders = $this->table('orders');
        if (!$orders->hasColumn('archived_at')) {
            $orders->addColumn('archived_at', 'datetime', ['null' => true, 'after' => 'is_archived'])->update();
        }
        $this->execute('UPDATE orders SET archived_at = created_at WHERE is_archived = 1 AND archived_at IS NULL');
    }

    public function down(): void
    {
        $orders = $this->table('orders');
        if ($orders->hasColumn('archived_at')) {
            $orders->removeColumn('archived_at')->update();
        }
    }
}
