<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPurchaseModeToStoreCoupons extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('store_coupons')) {
            return;
        }

        $table = $this->table('store_coupons');

        if (!$table->hasColumn('purchase_mode')) {
            $table->addColumn('purchase_mode', 'enum', [
                'values' => ['ALWAYS', 'FIRST_PURCHASE_ONLY'],
                'default' => 'ALWAYS',
                'after' => 'scope'
            ])->update();
        }
    }
}

