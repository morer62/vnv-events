<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEmployeeHours extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("employee_hours", [
            "id" => "id",
            "signed" => false
        ]);

        $table->addColumn("id_user", "integer")
              ->addColumn("start_time", "datetime")
              ->addColumn("end_time", "datetime", ["null" => true])
              ->addColumn("duration_hours", "float", ["default" => 0])
              ->addColumn("is_paid", "boolean", ["default" => false])
              ->addColumn("id_payment", "integer", ["null" => true])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS employee_hours");
    }
}
