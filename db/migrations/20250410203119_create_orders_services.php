<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersServices extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders_services", [
            "id" => "id",
            "signed" => false
        ]);

        $table->addColumn("name", "string", ["null" => false])
              ->addColumn("description", "text", ["null" => true])
              ->addColumn("price", "decimal", ["precision" => 10, "scale" => 2, "null" => false])
              ->addColumn("requirements", "text", ["null" => true])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"]);

        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders_services");
    }
}
