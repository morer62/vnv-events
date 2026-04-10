<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateStoreCouponsSystem extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('store_coupons')) {
            $this->table('store_coupons')
                ->addColumn('id_owner', 'integer')
                ->addColumn('code', 'string', ['limit' => 80])
                ->addColumn('scope', 'enum', ['values' => ['GLOBAL', 'CUSTOMER'], 'default' => 'GLOBAL'])
                ->addColumn('discount_type', 'enum', ['values' => ['PERCENT', 'FIXED'], 'default' => 'PERCENT'])
                ->addColumn('discount_value', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
                ->addColumn('status', 'enum', ['values' => ['ACTIVE', 'INACTIVE'], 'default' => 'ACTIVE'])
                ->addColumn('starts_at', 'datetime', ['null' => true])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('max_total_uses', 'integer', ['default' => 1])
                ->addColumn('total_uses', 'integer', ['default' => 0])
                ->addColumn('max_uses_per_customer', 'integer', ['default' => 1])
                ->addColumn('min_order_total', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['id_owner', 'code'], ['unique' => true, 'name' => 'uniq_store_coupon_owner_code'])
                ->addIndex(['id_owner', 'status'])
                ->create();
        }

        if (!$this->hasTable('store_coupon_customers')) {
            $this->table('store_coupon_customers')
                ->addColumn('id_coupon', 'integer')
                ->addColumn('id_user', 'integer', ['null' => true])
                ->addColumn('email', 'string', ['limit' => 190])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['id_coupon', 'email'], ['unique' => true, 'name' => 'uniq_store_coupon_customer_email'])
                ->addIndex(['id_coupon', 'id_user'])
                ->create();
        }

        if (!$this->hasTable('store_coupon_redemptions')) {
            $this->table('store_coupon_redemptions')
                ->addColumn('id_coupon', 'integer')
                ->addColumn('id_owner', 'integer')
                ->addColumn('id_store_order', 'integer')
                ->addColumn('id_user', 'integer', ['null' => true])
                ->addColumn('email', 'string', ['limit' => 190, 'null' => true])
                ->addColumn('discount_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
                ->addColumn('redeemed_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['id_coupon'])
                ->addIndex(['id_store_order'])
                ->addIndex(['id_coupon', 'id_user'])
                ->addIndex(['id_coupon', 'email'])
                ->create();
        }

        if ($this->hasTable('store_carts')) {
            $table = $this->table('store_carts');
            if (!$table->hasColumn('coupon_code')) {
                $table->addColumn('coupon_code', 'string', ['limit' => 80, 'null' => true]);
            }
            if (!$table->hasColumn('id_coupon')) {
                $table->addColumn('id_coupon', 'integer', ['null' => true]);
            }
            if (!$table->hasColumn('coupon_discount')) {
                $table->addColumn('coupon_discount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0]);
            }
            $table->update();
        }

        if ($this->hasTable('store_orders')) {
            $table = $this->table('store_orders');
            if (!$table->hasColumn('coupon_code')) {
                $table->addColumn('coupon_code', 'string', ['limit' => 80, 'null' => true]);
            }
            if (!$table->hasColumn('id_coupon')) {
                $table->addColumn('id_coupon', 'integer', ['null' => true]);
            }
            if (!$table->hasColumn('coupon_discount')) {
                $table->addColumn('coupon_discount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0]);
            }
            $table->update();
        }
    }
}

