<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class VenueStatus extends AbstractMigration
{

    public function up(): void
    {
        $this->table('venues')
            ->addColumn('status', 'string', [
                'default' => 'PENDING',
            ])
            ->update();
    }

    public function down(): void {
        $this->table('venues')
            ->removeColumn('status')
            ->update();
    }
}
