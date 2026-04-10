<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrders extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders", ["id" => "id", "signed" => false]);

        $table->addColumn("id_user", "integer", ["null" => false])
              ->addColumn("event_date", "date", ["null" => false])
              ->addColumn("address", "string", ["null" => false])
              ->addColumn("start_time", "time", ["null" => false])
              ->addColumn("end_time", "time", ["null" => false])
              ->addColumn("discount_type", "enum", [
                  "values" => ["amount", "percentage"],
                  "default" => null,
                  "null" => true
              ])
              ->addColumn("discount_value", "decimal", ["precision" => 10, "scale" => 2, "default" => 0])
              ->addColumn("id_contract", "integer", ["null" => true])
              ->addColumn("payment_status", "enum", [
                  "values" => ["paid_full", "paid_half", "pending"],
                  "default" => "pending"
              ])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->addForeignKey("id_user", "users", "id", ["delete" => "CASCADE"])
              ->addForeignKey("id_contract", "orders_contracts", "id", ["delete" => "SET_NULL"]);
              
        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders");
    }
}
