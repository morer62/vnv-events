<?php

use Phinx\Migration\AbstractMigration;

class CreateCrmLeads extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('crm_leads');
        $table
            ->addColumn('id_user', 'integer')
            ->addColumn('id_category', 'integer', ['null' => true])
            ->addColumn('id_status', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('email', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('id_user', 'users', 'id', ['delete'=> 'CASCADE'])
            ->addForeignKey('id_category', 'crm_categories', 'id', ['delete'=> 'SET_NULL'])
            ->addForeignKey('id_status', 'crm_status', 'id', ['delete'=> 'SET_NULL'])
            ->create();
    }
}
