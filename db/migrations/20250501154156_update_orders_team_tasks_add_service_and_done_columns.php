<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateOrdersTeamTasksAddServiceAndDoneColumns extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('orders_team_tasks');

        if (!$table->hasColumn('id_service')) {
            $table->addColumn('id_service', 'integer', ['default' => 0, 'null' => false]);
        }

        if (!$table->hasColumn('is_done')) {
            $table->addColumn('is_done', 'boolean', ['default' => false, 'null' => false]);
        }

        if ($table->hasColumn('is_manual')) {
            $table->removeColumn('is_manual');
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('orders_team_tasks');

        if ($table->hasColumn('id_service')) {
            $table->removeColumn('id_service');
        }

        if ($table->hasColumn('is_done')) {
            $table->removeColumn('is_done');
        }

        if (!$table->hasColumn('is_manual')) {
            $table->addColumn('is_manual', 'boolean', ['default' => false, 'null' => true]);
        }

        $table->update();
    }
}
