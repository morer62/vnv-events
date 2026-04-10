<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePayrollPayments extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("payroll_payments", [
            "id" => false,
            "primary_key" => "id"
        ]);

        $table->addColumn("id", "integer", ["signed" => false, "identity" => true])
              ->addColumn("id_user", "integer", ["signed" => false, "null" => false])
              ->addColumn("hours_count", "integer", ["signed" => false, "default" => 0])
              ->addColumn("hours_ids", "text", ["null" => false])
              ->addColumn("proof_url", "text", ["null" => true])
              ->addColumn("paid_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->create();
    }

    public function down(): void
    {
        $this->table("payroll_payments")->drop()->save();
    }
}
