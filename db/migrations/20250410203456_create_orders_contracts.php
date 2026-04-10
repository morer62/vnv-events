<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersContracts extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders_contracts", ["id" => "id", "signed" => false]);
        $table->addColumn("title", "string", ["null" => false])
              ->addColumn("content", "text", ["null" => false])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"]);
        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders_contracts");
    }
}
