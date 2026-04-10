<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserFeaturePermissionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_feature_permissions')
            ->addColumn('id_user', 'integer', ['signed' => false])
            ->addColumn('feature_slug', 'string', ['limit' => 100])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('id_user', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION'
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('user_feature_permissions')->drop()->save();
    }
}
