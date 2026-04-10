<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersFiles extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("orders_files", ["id" => "id", "signed" => false]);

        $table->addColumn("id_order", "integer", ["null" => false])
              ->addColumn("title", "string", ["null" => true])
              ->addColumn("description", "text", ["null" => true])
              ->addColumn("file_path", "string", ["null" => false])
              ->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"])
              ->addForeignKey("id_order", "orders", "id", ["delete" => "CASCADE"]);

        $table->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS orders_files");
    }
}
