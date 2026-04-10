<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateStorageContainersAndItems extends AbstractMigration
{
    public function up(): void
    {
        // Tabla de contenedores
        $this->table('storage_containers')
            ->addColumn('id_user', 'integer')
            ->addColumn('name', 'string')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();

        // Tabla de ítems dentro de los contenedores
        $this->table('storage_items')
            ->addColumn('id_container', 'integer')
            ->addColumn('name', 'string')
            ->addColumn('quantity', 'integer', ['default' => 1])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        $this->table('storage_items')->drop()->save();
        $this->table('storage_containers')->drop()->save();
    }
}
