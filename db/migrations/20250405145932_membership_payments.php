<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MembershipPayments extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('membership_payments')) {
            return;
        }

        $this->table('membership_payments')
            ->addColumn('id_user', 'integer')
            ->addColumn('payment_date', 'date')
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('payment_type', 'enum', ['values' => ['monthly', 'annual']])
            ->addColumn('stripe_payment_id', 'string', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('id_user', 'users', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('membership_payments')) {
            $this->table('membership_payments')->drop()->save();
        }
    }
}
