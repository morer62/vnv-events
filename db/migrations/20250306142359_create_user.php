<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUser extends AbstractMigration
{
    public function up() : void {
        $table = $this->table("users", [
            "id" => 'id',
            "signed" => false
        ]);

        $table->addColumn("name", "string", ["null" => false]);
        $table->addColumn("lastname", "string", ["null" => false]);
        $table->addColumn("email", "string", ["null" => false]);
        $table->addColumn("password", "string", ["null" => false]);
        $table->addColumn("level", "string");
        $table->addColumn("phone", "string");
        $table->addColumn("phone_code", "string");
        $table->addColumn("phone_validation", "integer", ["default" => "0"]);
        $table->addColumn("membership_due_date", "date", ["default" => null]);

        $table->create();
    }

    public function down() : void {
        $this->execute("DROP TABLE IF EXISTS users");
    }
}
