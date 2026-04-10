<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePayrollTimeLogs extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("payroll_time_logs", [
            "id" => 'id',
            "signed" => false
        ]);

        $table->addColumn("id_user", "integer", ["null" => false, "signed" => false]);
        $table->addColumn("start_time", "datetime", ["null" => false]);
        $table->addColumn("end_time", "datetime", ["null" => true, "default" => null]);
        $table->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"]);

        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS payroll_time_logs");
    }
}
