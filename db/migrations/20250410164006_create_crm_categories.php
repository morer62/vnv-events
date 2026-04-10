<?php

use Phinx\Migration\AbstractMigration;

class CreateCrmCategories extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('crm_categories');
        $table
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
