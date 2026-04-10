<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class VenueAndCategories extends AbstractMigration
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
        $table = $this->table('venue_categories', [
            "id" => "id",
            "signed" => false,
        ]);

        $table->addColumn('name', 'string')
            ->addColumn('description', 'string')
            ->create();

        $tableVenue = $this->table('venues', [
            "id" => "id",
            "signed" => false,
        ]);

        $tableVenue->addColumn('name', 'string');
        $tableVenue->addColumn('description', 'string');
        $tableVenue->addColumn('address', 'string');
        $tableVenue->addColumn('location', 'string');

        $tableVenue->addColumn('category_id', 'integer');
        $tableVenue->addColumn("user_id", "integer");

        $tableVenue->create();

        $tablePro = $this->table('venue_promotions', [
            "id" => "id",
            "signed" => false,
        ]);

        $tablePro->addColumn('start_date', 'string');
        $tablePro->addColumn('end_date', 'string');
        $tablePro->addColumn("image", "string");
        $tablePro->addColumn('venue_id', 'integer');

        $tablePro->create();

        $tableEvent = $this->table('venue_events', [
            "id" => "id",
            "signed" => false,
        ]);

        $tableEvent->addColumn('start_date', 'string');
        $tableEvent->addColumn('end_date', 'string');
        $tableEvent->addColumn("image", "string");
        $tableEvent->addColumn('venue_id', 'integer');

        $tableEvent->create();

        $tablePhoto = $this->table("venue_photos", [
            "id" => "id",
            "signed" => false,
        ]);

        $tablePhoto->addColumn('venue_id', 'integer');
        $tablePhoto->addColumn('image', 'string');
        $tablePhoto->create();
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS venue_photos");
        $this->execute("DROP TABLE IF EXISTS venue_events");
        $this->execute("DROP TABLE IF EXISTS venue_promotions");
        $this->execute("DROP TABLE IF EXISTS venues");
        $this->execute("DROP TABLE IF EXISTS venue_categories");
    }
}
