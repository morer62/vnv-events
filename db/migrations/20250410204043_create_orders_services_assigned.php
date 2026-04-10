<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersServicesAssigned extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders_services_assigned", ["id" => "id", "signed" => false]);

        $table->addColumn("id_order", "integer", ["null" => false])
              ->addColumn("id_service", "integer", ["null" => false])
              ->addColumn("quantity", "integer", ["default" => 1])
              ->addColumn("subtotal", "decimal", ["precision" => 10, "scale" => 2])
              ->addForeignKey("id_order", "orders", "id", ["delete" => "CASCADE"])
              ->addForeignKey("id_service", "orders_services", "id", ["delete" => "CASCADE"]);

        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders_services_assigned");
    }
}
