<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIdOwnerToPlannerHubTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table("crm_categories")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("crm_lead_status_history")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("crm_leads")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("crm_status")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("crm_whatsapp_messages")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("orders_contracts")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("orders_files")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("orders_service_tasks")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("orders_services")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("orders_services_assigned")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("orders_team_tasks")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("payroll_hours")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("payroll_payments")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("payroll_time_logs")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("storage_containers")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table("storage_items")
            ->addColumn("id_owner", "integer", ["null" => true])
            ->update();

        $this->table('orders')
            ->addColumn('id_owner', 'integer', ['null' => true])
            ->update();

    }

    public function down(): void
    {
        $this->table("crm_categories")
            ->removeColumn("id_owner")
            ->update();

        $this->table("crm_lead_status_history")
            ->removeColumn("id_owner")
            ->update();

        $this->table("crm_leads")
            ->removeColumn("id_owner")
            ->update();

        $this->table("crm_status")
            ->removeColumn("id_owner")
            ->update();

        $this->table("crm_whatsapp_messages")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders_contracts")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders_files")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders_service_tasks")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders_services")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders_services_assigned")
            ->removeColumn("id_owner")
            ->update();

        $this->table("orders_team_tasks")
            ->removeColumn("id_owner")
            ->update();

        $this->table("payroll_hours")
            ->removeColumn("id_owner")
            ->update();

        $this->table("payroll_payments")
            ->removeColumn("id_owner")
            ->update();

        $this->table("payroll_time_logs")
            ->removeColumn("id_owner")
            ->update();

        $this->table("storage_containers")
            ->removeColumn("id_owner")
            ->update();

        $this->table("storage_items")
            ->removeColumn("id_owner")
            ->update();

    }
}
