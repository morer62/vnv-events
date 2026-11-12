<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCommercialPartnershipCrm extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `commercial_organizations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `name` VARCHAR(191) NOT NULL,
  `website` VARCHAR(255) NULL,
  `legal_status` VARCHAR(120) NULL,
  `ein` VARCHAR(40) NULL,
  `organization_type` VARCHAR(60) NOT NULL DEFAULT 'prospect',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_commercial_org_owner_name` (`id_owner`,`site_key`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_contacts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `organization_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(191) NOT NULL,
  `role_title` VARCHAR(150) NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(80) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_commercial_contact_owner_email` (`id_owner`,`site_key`,`email`),
  KEY `idx_commercial_contact_org` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_opportunities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `organization_id` BIGINT UNSIGNED NULL,
  `contact_id` BIGINT UNSIGNED NULL,
  `program` ENUM('NONPROFIT','CORPORATE','SPONSORSHIP','ACTIVATION','ORIGINAL') NOT NULL,
  `pipeline` VARCHAR(60) NOT NULL,
  `stage` VARCHAR(60) NOT NULL,
  `title` VARCHAR(220) NOT NULL,
  `event_name` VARCHAR(220) NULL,
  `event_date` DATE NULL,
  `venue` VARCHAR(255) NULL,
  `city` VARCHAR(120) NULL,
  `guest_count` INT UNSIGNED NULL,
  `budget_range` VARCHAR(120) NULL,
  `requested_services` TEXT NULL,
  `assigned_owner_name` VARCHAR(120) NULL,
  `assigned_user_id` INT UNSIGNED NULL,
  `source` VARCHAR(80) NOT NULL DEFAULT 'website',
  `utm_json` TEXT NULL,
  `payload_json` LONGTEXT NULL,
  `next_follow_up_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_commercial_opp_pipeline` (`id_owner`,`site_key`,`pipeline`,`stage`,`created_at`),
  KEY `idx_commercial_opp_contact` (`contact_id`),
  KEY `idx_commercial_opp_followup` (`id_owner`,`next_follow_up_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_projects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `order_id` INT UNSIGNED NULL,
  `name` VARCHAR(220) NOT NULL, `status` VARCHAR(60) NOT NULL DEFAULT 'PLANNING',
  `starts_at` DATETIME NULL, `ends_at` DATETIME NULL, `scope_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_project_opp` (`id_owner`,`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_sponsorship_inventory` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `project_id` BIGINT UNSIGNED NULL, `name` VARCHAR(191) NOT NULL, `description` TEXT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'AVAILABLE', `capacity` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_inventory_project` (`id_owner`,`project_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_sponsor_deals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `inventory_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(60) NOT NULL DEFAULT 'INTRO_CONSENTED', `contracted_amount` DECIMAL(12,2) NULL,
  `collected_amount` DECIMAL(12,2) NULL, `fee_percent` DECIMAL(5,2) NULL, `contract_reference` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_sponsor_deal` (`id_owner`,`opportunity_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_activation_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `project_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(60) NOT NULL DEFAULT 'BRIEF', `activation_type` VARCHAR(150) NULL,
  `dimensions` VARCHAR(150) NULL, `approvals_json` TEXT NULL, `deliverables_json` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_activation` (`id_owner`,`opportunity_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_proposals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` VARCHAR(40) NOT NULL DEFAULT 'DRAFT', `eligible_fee_amount` DECIMAL(12,2) NULL,
  `scope_json` LONGTEXT NULL, `sent_at` DATETIME NULL, `accepted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_proposal` (`id_owner`,`opportunity_id`,`version_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_invoice_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `invoice_id` BIGINT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_commercial_invoice_link` (`id_owner`,`opportunity_id`,`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_support_grants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `status` VARCHAR(40) NOT NULL DEFAULT 'UNDER_REVIEW',
  `consultation_hours` DECIMAL(7,2) NULL, `discount_food_staff_percent` DECIMAL(5,2) NULL,
  `discount_decor_percent` DECIMAL(5,2) NULL, `approved_scope` TEXT NULL, `approved_by` INT UNSIGNED NULL, `approved_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_support_grant` (`id_owner`,`opportunity_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `activity_type` VARCHAR(60) NOT NULL,
  `summary` VARCHAR(500) NOT NULL, `details_json` LONGTEXT NULL, `actor_user_id` INT UNSIGNED NULL,
  `due_at` DATETIME NULL, `completed_at` DATETIME NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_activity` (`id_owner`,`opportunity_id`,`created_at`), KEY `idx_commercial_activity_due` (`id_owner`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_commissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NOT NULL, `source_type` ENUM('CORPORATE_FEE','SPONSORSHIP_FEE','ORIGINAL_CONTRIBUTION') NOT NULL,
  `source_reference` VARCHAR(191) NOT NULL, `eligible_collected_amount` DECIMAL(12,2) NOT NULL,
  `oriana_percent` DECIMAL(5,2) NOT NULL DEFAULT 35.00, `florida_percent` DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  `vnv_reserve_percent` DECIMAL(5,2) NOT NULL DEFAULT 35.00, `status` VARCHAR(40) NOT NULL DEFAULT 'PENDING_APPROVAL',
  `approved_by` INT UNSIGNED NULL, `approved_at` DATETIME NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_commercial_commission_source` (`id_owner`,`source_type`,`source_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_consents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `contact_id` BIGINT UNSIGNED NOT NULL, `opportunity_id` BIGINT UNSIGNED NULL, `consent_type` VARCHAR(80) NOT NULL,
  `consented` TINYINT(1) NOT NULL DEFAULT 0, `consent_text` TEXT NULL, `source_ip_hash` CHAR(64) NULL,
  `user_agent` VARCHAR(500) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_consent` (`id_owner`,`contact_id`,`consent_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_media_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `opportunity_id` BIGINT UNSIGNED NULL, `project_id` BIGINT UNSIGNED NULL, `asset_type` VARCHAR(60) NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL, `label` VARCHAR(191) NULL, `created_by` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_media` (`id_owner`,`opportunity_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `site_key` VARCHAR(40) NOT NULL DEFAULT 'vnvevents',
  `entity_type` VARCHAR(80) NOT NULL, `entity_id` BIGINT UNSIGNED NOT NULL, `action` VARCHAR(80) NOT NULL,
  `before_json` LONGTEXT NULL, `after_json` LONGTEXT NULL, `actor_user_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_commercial_audit` (`id_owner`,`entity_type`,`entity_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        foreach ([
            'commercial_audit_logs', 'commercial_media_assets', 'commercial_consents',
            'commercial_commissions', 'commercial_activities', 'commercial_support_grants',
            'commercial_invoice_links', 'commercial_proposals', 'commercial_activation_orders',
            'commercial_sponsor_deals', 'commercial_sponsorship_inventory', 'commercial_projects',
            'commercial_opportunities', 'commercial_contacts', 'commercial_organizations',
        ] as $table) {
            $this->execute("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
