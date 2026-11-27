<?php

use Phinx\Migration\AbstractMigration;

final class CreateProductionsCenterAudit extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(file_get_contents(__DIR__.'/../sql/productions-center-audit.sql'));
    }
    public function down(): void
    {
        throw new RuntimeException('Retain the administrative audit history when rolling back the application.');
    }
}
