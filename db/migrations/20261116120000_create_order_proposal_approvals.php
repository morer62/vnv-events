<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrderProposalApprovals extends AbstractMigration
{
    public function up(): void
    {
        $orders = $this->table('orders');
        if (!$orders->hasColumn('proposal_requires_approval')) {
            $orders
                ->addColumn('proposal_requires_approval', 'boolean', [
                    'default' => false,
                    'null' => false,
                    'after' => 'notes',
                ])
                ->update();
        }

        $this->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `order_proposals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` INT NOT NULL,
  `id_owner` INT NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  `current_revision_id` BIGINT UNSIGNED NULL,
  `approved_revision_id` BIGINT UNSIGNED NULL,
  `created_by` INT NULL,
  `updated_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_proposals_order` (`id_order`),
  KEY `idx_order_proposals_owner_status` (`id_owner`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_proposal_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proposal` BIGINT UNSIGNED NOT NULL,
  `revision_number` INT UNSIGNED NOT NULL,
  `content_html` MEDIUMTEXT NOT NULL,
  `content_text` TEXT NULL,
  `change_note` VARCHAR(500) NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_proposal_revision` (`id_proposal`,`revision_number`),
  KEY `idx_order_proposal_revisions_created` (`id_proposal`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_proposal_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proposal` BIGINT UNSIGNED NOT NULL,
  `id_revision` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  `actor_user_id` INT NULL,
  `reason` VARCHAR(500) NULL,
  `signature_method` VARCHAR(30) NULL,
  `signature_value` VARCHAR(500) NULL,
  `signed_pdf_path` VARCHAR(1000) NULL,
  `content_hash` CHAR(64) NULL,
  `ip_address` VARCHAR(64) NULL,
  `user_agent` VARCHAR(1000) NULL,
  `user_local_timestamp` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_proposal_approval_action` (`id_proposal`,`id_revision`,`action`),
  KEY `idx_order_proposal_approvals_history` (`id_proposal`,`created_at`),
  KEY `idx_order_proposal_approval_revision` (`id_revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_proposal_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proposal` BIGINT UNSIGNED NOT NULL,
  `id_revision` BIGINT UNSIGNED NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `actor_user_id` INT NULL,
  `reason` VARCHAR(500) NULL,
  `metadata_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_proposal_audit_history` (`id_proposal`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        foreach (['order_proposal_audit_logs', 'order_proposal_approvals', 'order_proposal_revisions', 'order_proposals'] as $table) {
            $this->execute("DROP TABLE IF EXISTS `{$table}`");
        }

        $orders = $this->table('orders');
        if ($orders->hasColumn('proposal_requires_approval')) {
            $orders->removeColumn('proposal_requires_approval')->update();
        }
    }
}
