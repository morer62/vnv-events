<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCrmWhatsappMessages extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table("crm_whatsapp_messages", [
            "id" => "id",
            "signed" => false
        ]);

        $table->addColumn("id_lead", "integer", ["null" => false]);
        $table->addColumn("message", "text", ["null" => true]);
        $table->addColumn("media_url", "text", ["null" => true]);
        $table->addColumn("media_type", "string", ["null" => true, "limit" => 100]);
        $table->addColumn("direction", "enum", ["values" => ["sent", "received"]]);
        $table->addColumn("created_at", "datetime", ["default" => "CURRENT_TIMESTAMP"]);

        $table->create();
    }

    public function down(): void
    {
        $this->table("crm_whatsapp_messages")->drop()->save();
    }
}
