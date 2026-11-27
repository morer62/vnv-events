<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class ProductionsWorkboardSchema
{
    /** Idempotent on both MySQL and MariaDB. Existing order IDs and task completion flags are preserved. */
    public static function apply(PDO $db): void
    {
        $columns = $db->query("SHOW COLUMNS FROM orders_team_tasks")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach (['id_order','id_owner','id_user','id_service','is_done','task_description'] as $required) {
            if (!isset($columns[$required])) {
                throw new RuntimeException('Install the order task manager before the Productions workboard.');
            }
        }
        $alter = [];
        if ($columns['id_order']['Null'] !== 'YES') {
            $type = $columns['id_order']['Type'];
            if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/iD', $type)) {
                throw new RuntimeException('Review the order key type before changing its nullability.');
            }
            $alter[] = "MODIFY COLUMN id_order $type NULL DEFAULT NULL";
        }
        foreach ([
            'pmc_project_id' => 'BIGINT UNSIGNED NULL',
            'pmc_task_key' => 'VARCHAR(32) NULL',
            'pmc_phase' => 'VARCHAR(40) NULL',
            'pmc_due_date' => 'DATE NULL',
            'pmc_priority' => 'VARCHAR(12) NULL',
            'pmc_dependencies' => 'VARCHAR(150) NULL',
            'pmc_acceptance' => 'VARCHAR(700) NULL',
            'pmc_role' => 'VARCHAR(90) NULL',
            'pmc_evidence' => 'VARCHAR(500) NULL',
            'pmc_blocker' => 'VARCHAR(500) NULL'
        ] as $field => $type) {
            if (!isset($columns[$field])) {
                $alter[] = "ADD COLUMN $field $type";
            }
        }
        if (!$db->query("SHOW INDEX FROM orders_team_tasks WHERE Key_name = 'uq_pmc_project_task'")->fetch()) {
            $alter[] = 'ADD UNIQUE KEY uq_pmc_project_task (pmc_project_id,pmc_task_key)';
        }
        if ($alter) {
            $db->exec('ALTER TABLE orders_team_tasks ' . implode(', ', $alter));
        }
        if (!$db->query("SHOW COLUMNS FROM commercial_opportunities LIKE 'relationship_category'")->fetch()) {
            $db->exec('ALTER TABLE commercial_opportunities ADD COLUMN relationship_category VARCHAR(40) NULL');
        }
        if (!$db->query("SHOW COLUMNS FROM commercial_projects LIKE 'original_edition_key'")->fetch()) {
            $db->exec('ALTER TABLE commercial_projects ADD COLUMN original_edition_key VARCHAR(90) NULL');
        }
        if (!$db->query("SHOW INDEX FROM commercial_projects WHERE Key_name = 'uq_pmc_edition'")->fetch()) {
            $db->exec('ALTER TABLE commercial_projects ADD UNIQUE KEY uq_pmc_edition (id_owner,site_key,original_edition_key)');
        }
    }
}
