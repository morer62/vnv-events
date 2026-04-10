<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePayrollHours extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("payroll_hours", [
            "id" => false,
            "primary_key" => "id"
        ]);

        $table->addColumn("id", "integer", ["signed" => false, "identity" => true])
              ->addColumn("id_user", "integer", ["signed" => false, "null" => false])
              ->addColumn("start_time", "datetime", ["null" => false])
              ->addColumn("end_time", "datetime", ["null" => true])
              ->addColumn("is_paid", "boolean", ["default" => 0])
              ->addColumn("proof_url", "text", ["null" => true])
              ->addColumn("paid_at", "datetime", ["null" => true])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->create();
    }

    public function down(): void
    {
        $this->table("payroll_hours")->drop()->save();
    }
}
