<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCrmLeadStatusHistory extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("crm_lead_status_history", [
            "id" => "id",
            "signed" => false
        ]);

        $table
            ->addColumn("id_lead", "integer", ["null" => false, "signed" => false])
            ->addColumn("old_status", "string", ["limit" => 100, "null" => true])
            ->addColumn("new_status", "string", ["limit" => 100, "null" => false])
            ->addColumn("comment", "text", ["null" => true])
            ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
            ->addColumn("id_user", "integer", ["null" => true, "signed" => false])
            ->addIndex("id_lead")
            ->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS crm_lead_status_history");
    }
}
