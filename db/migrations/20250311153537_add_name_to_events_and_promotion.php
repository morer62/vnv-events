<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddNameToEventsAndPromotion extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up(): void
    {
        $this->table('venue_events')
            ->addColumn('name', 'string')
            ->update();

        $this->table('venue_promotions')
            ->addColumn('name', 'string')
            ->update();
    }

    public function down(): void {
        $this->table('venue_events')
            ->removeColumn('name')
            ->update();

        $this->table('venue_promotions')
            ->removeColumn('name')
            ->update();
    }
}
