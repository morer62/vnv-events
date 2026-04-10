<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEmployeePayments extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("employee_payments", [
            "id" => "id",
            "signed" => false
        ]);

        $table->addColumn("id_user", "integer")
              ->addColumn("payment_date", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->addColumn("proof_url", "string", ["null" => true])
              ->addColumn("total_hours", "float", ["default" => 0])
              ->addColumn("total_amount", "decimal", ["precision" => 10, "scale" => 2])
              ->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS employee_payments");
    }
}
