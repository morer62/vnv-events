<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersTeamTasks extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders_team_tasks", ["id" => "id", "signed" => false]);

        $table->addColumn("id_order", "integer", ["null" => false])
              ->addColumn("id_user", "integer", ["null" => false])
              ->addColumn("task_description", "text", ["null" => false])
              ->addColumn("is_manual", "boolean", ["default" => 0])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->addForeignKey("id_order", "orders", "id", ["delete" => "CASCADE"])
              ->addForeignKey("id_user", "users", "id", ["delete" => "CASCADE"]);

        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders_team_tasks");
    }
}
