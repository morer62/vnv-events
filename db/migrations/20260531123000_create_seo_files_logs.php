<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSeoFilesLogs extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('seo_files_logs')) {
            return;
        }

        $this->table('seo_files_logs')
            ->addColumn('file_type', 'string', ['limit' => 80])
            ->addColumn('generated_by', 'integer', ['null' => true])
            ->addColumn('status', 'enum', [
                'values' => ['success', 'failed', 'partial'],
                'default' => 'success',
            ])
            ->addColumn('message', 'text', ['null' => true])
            ->addColumn('items_count', 'integer', ['default' => 0])
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('public_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['file_type'])
            ->addIndex(['created_at'])
            ->create();
    }
}
