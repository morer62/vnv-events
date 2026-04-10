<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIdOwnerToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('id_owner', 'integer', ['null' => true, 'after' => 'id']) // 'after' es opcional, solo para orden
            ->addForeignKey('id_owner', 'users', 'id', ['delete'=> 'SET_NULL', 'update'=> 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->dropForeignKey('id_owner')
            ->removeColumn('id_owner')
            ->update();
    }
}
