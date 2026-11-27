<?php

declare(strict_types=1);

use App\Services\ProductionsWorkboardSchema;
use Phinx\Migration\AbstractMigration;

final class ExtendProductionWorkboard extends AbstractMigration
{
    public function up(): void
    {
        ProductionsWorkboardSchema::apply($this->getAdapter()->getConnection());
    }

    public function down(): void
    {
        throw new RuntimeException('Roll back application code while retaining existing project schedules, evidence and deadlines.');
    }
}
