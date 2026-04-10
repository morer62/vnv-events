<?php

use Phinx\Migration\AbstractMigration;

class CreateCrmStatus extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('crm_status');
        $table
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('color', 'string', ['limit' => 20])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
