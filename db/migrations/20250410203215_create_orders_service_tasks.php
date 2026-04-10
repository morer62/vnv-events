<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersServiceTasks extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders_service_tasks", [
            "id" => "id",
            "signed" => false
        ]);

        $table->addColumn("id_service", "integer", ["null" => false])
              ->addColumn("task_name", "string", ["null" => false])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"]);

        $table->addForeignKey("id_service", "orders_services", "id", [
            "delete" => "CASCADE",
            "update" => "NO_ACTION"
        ]);

        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders_service_tasks");
    }
}
