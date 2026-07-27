-- VNV Events Level 1 AI Agents infrastructure.
-- Safe to run repeatedly.

CREATE TABLE IF NOT EXISTS `ai_agents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `agent_key` VARCHAR(100) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` TEXT NULL,
  `category` VARCHAR(80) NOT NULL DEFAULT 'operations',
  `status` ENUM('DRAFT','ACTIVE','PAUSED','ERROR','SETUP_REQUIRED') NOT NULL DEFAULT 'DRAFT',
  `approval_mode` ENUM('ALWAYS','IMPORTANT','NEVER') NOT NULL DEFAULT 'ALWAYS',
  `schedule_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `schedule_expression` VARCHAR(120) NULL,
  `webhook_token_hash` CHAR(64) NULL,
  `webhook_token_hint` VARCHAR(16) NULL,
  `settings_json` MEDIUMTEXT NULL,
  `last_run_at` DATETIME NULL,
  `next_run_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_agents_owner_key` (`id_owner`, `agent_key`),
  KEY `idx_ai_agents_status` (`id_owner`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_agent` INT UNSIGNED NOT NULL,
  `id_owner` INT NOT NULL,
  `trigger_type` ENUM('MANUAL','SCHEDULE','WEBHOOK','SYSTEM') NOT NULL DEFAULT 'MANUAL',
  `status` ENUM('QUEUED','RUNNING','AWAITING_APPROVAL','COMPLETED','FAILED','CANCELLED') NOT NULL DEFAULT 'QUEUED',
  `input_json` MEDIUMTEXT NULL,
  `output_json` LONGTEXT NULL,
  `error_message` TEXT NULL,
  `created_by` INT NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_agent_runs_agent` (`id_agent`, `created_at`),
  KEY `idx_ai_agent_runs_owner_status` (`id_owner`, `status`, `created_at`),
  CONSTRAINT `fk_ai_agent_runs_agent` FOREIGN KEY (`id_agent`) REFERENCES `ai_agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_run` BIGINT UNSIGNED NOT NULL,
  `id_agent` INT UNSIGNED NOT NULL,
  `id_owner` INT NOT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `payload_json` LONGTEXT NULL,
  `status` ENUM('PENDING','PROCESSING','REVISION_REQUESTED','APPROVED','REJECTED','EXECUTED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  `requested_by` INT NULL,
  `reviewed_by` INT NULL,
  `review_note` TEXT NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_agent_approvals_owner` (`id_owner`, `status`, `created_at`),
  KEY `idx_ai_agent_approvals_run` (`id_run`),
  CONSTRAINT `fk_ai_agent_approvals_run` FOREIGN KEY (`id_run`) REFERENCES `ai_agent_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_agent_approvals_agent` FOREIGN KEY (`id_agent`) REFERENCES `ai_agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep existing installations aligned with the current approval workflow.
ALTER TABLE `ai_agent_approvals`
  MODIFY `status` ENUM('PENDING','PROCESSING','REVISION_REQUESTED','APPROVED','REJECTED','EXECUTED','EXPIRED') NOT NULL DEFAULT 'PENDING';

CREATE TABLE IF NOT EXISTS `ai_agent_media_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `id_user` INT NULL,
  `title` VARCHAR(255) NOT NULL,
  `source_url` TEXT NOT NULL,
  `source_name` VARCHAR(255) NULL,
  `mime_type` VARCHAR(120) NULL,
  `status` ENUM('UPLOADED','QUEUED','TRANSCRIBING','READY','RENDERING','COMPLETED','FAILED') NOT NULL DEFAULT 'UPLOADED',
  `transcript_text` LONGTEXT NULL,
  `transcript_json` LONGTEXT NULL,
  `subtitles_srt` LONGTEXT NULL,
  `edit_plan_json` LONGTEXT NULL,
  `output_url` TEXT NULL,
  `error_message` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_agent_media_owner` (`id_owner`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ai_agent_media_jobs`
  MODIFY `status` ENUM('UPLOADED','QUEUED','TRANSCRIBING','READY','RENDERING','COMPLETED','FAILED') NOT NULL DEFAULT 'UPLOADED';

CREATE TABLE IF NOT EXISTS `ai_agent_connections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_agent` INT UNSIGNED NOT NULL,
  `id_owner` INT NOT NULL,
  `platform` ENUM('facebook','instagram','linkedin','youtube','whatsapp') NOT NULL,
  `account_label` VARCHAR(180) NULL,
  `account_identifier` VARCHAR(255) NULL,
  `credentials_encrypted` LONGTEXT NOT NULL,
  `credential_hint` VARCHAR(20) NULL,
  `status` ENUM('CONFIGURED','VERIFIED','ERROR','DISCONNECTED') NOT NULL DEFAULT 'CONFIGURED',
  `last_error` TEXT NULL,
  `verified_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_agent_connection` (`id_owner`,`id_agent`,`platform`),
  CONSTRAINT `fk_ai_agent_connections_agent` FOREIGN KEY (`id_agent`) REFERENCES `ai_agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ai_agent_connections`
  MODIFY `platform` ENUM('facebook','instagram','linkedin','youtube','whatsapp') NOT NULL;

CREATE TABLE IF NOT EXISTS `ai_provider_connections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `provider` ENUM('openai','anthropic','gemini') NOT NULL,
  `api_key_encrypted` LONGTEXT NOT NULL,
  `key_hint` VARCHAR(20) NULL,
  `text_model` VARCHAR(120) NULL,
  `image_model` VARCHAR(120) NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default_text` TINYINT(1) NOT NULL DEFAULT 0,
  `is_default_image` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_provider_owner` (`id_owner`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_media_assets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `id_user` INT NULL,
  `asset_type` ENUM('LOGO','INTRO','OUTRO','OVERLAY','AUDIO') NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `asset_url` TEXT NOT NULL,
  `mime_type` VARCHAR(120) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_media_assets_owner` (`id_owner`,`asset_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_approval_executions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_approval` BIGINT UNSIGNED NOT NULL,
  `id_owner` INT NOT NULL, `attempt` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('PROCESSING','RETRY','SUCCEEDED','FAILED') NOT NULL DEFAULT 'PROCESSING',
  `external_reference` VARCHAR(255) NULL, `response_json` LONGTEXT NULL, `error_message` TEXT NULL,
  `next_retry_at` DATETIME NULL, `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`), KEY `idx_ai_execution_approval` (`id_approval`,`status`),
  KEY `idx_ai_execution_retry` (`status`,`next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_execution_locks` (
  `lock_key` VARCHAR(190) NOT NULL, `lock_token` CHAR(64) NOT NULL, `locked_until` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`lock_key`), KEY `idx_ai_locks_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_usage_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `id_agent` INT NULL, `id_run` BIGINT UNSIGNED NULL,
  `provider` VARCHAR(30) NOT NULL, `model` VARCHAR(100) NULL, `operation` VARCHAR(80) NOT NULL,
  `input_tokens` INT UNSIGNED NOT NULL DEFAULT 0, `output_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `estimated_cost_usd` DECIMAL(12,6) NULL, `metadata_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
  KEY `idx_ai_usage_owner_date` (`id_owner`,`created_at`), KEY `idx_ai_usage_run` (`id_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_meta_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `event_key` CHAR(64) NOT NULL,
  `object_type` VARCHAR(40) NULL, `payload_json` LONGTEXT NOT NULL,
  `status` ENUM('RECEIVED','PROCESSED','IGNORED','ERROR') NOT NULL DEFAULT 'RECEIVED', `error_message` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `processed_at` DATETIME NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ai_meta_event` (`event_key`),
  KEY `idx_ai_meta_owner_status` (`id_owner`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_video_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_media_job` BIGINT UNSIGNED NOT NULL,
  `id_owner` INT NOT NULL, `id_user` INT NULL, `revision_type` VARCHAR(40) NOT NULL,
  `transcript_text` LONGTEXT NULL, `subtitles_srt` LONGTEXT NULL, `edit_plan_json` LONGTEXT NULL,
  `notes` TEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_ai_video_revision_job` (`id_media_job`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_editorial_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `id_user` INT NULL,
  `name` VARCHAR(160) NOT NULL DEFAULT 'Weekly content plan',
  `articles_per_week` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `location_pages_per_week` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pages_per_week` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `social_posts_per_week` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `video_posts_per_week` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `instructions` TEXT NULL, `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_planned_at` DATETIME NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ai_editorial_owner_name` (`id_owner`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_owner` INT NOT NULL, `id_lead` INT NULL,
  `channel` ENUM('messenger','instagram','whatsapp') NOT NULL, `external_user_id` VARCHAR(190) NOT NULL,
  `display_name` VARCHAR(190) NULL, `status` ENUM('OPEN','WAITING_APPROVAL','HUMAN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `last_processed_message_id` BIGINT UNSIGNED NULL,
  `last_message_at` DATETIME NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ai_conversation_identity` (`id_owner`,`channel`,`external_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_agent_conversation_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_conversation` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('IN','OUT') NOT NULL, `external_message_id` VARCHAR(190) NULL, `message_text` TEXT NULL,
  `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ai_external_message` (`external_message_id`),
  KEY `idx_ai_conversation_message` (`id_conversation`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
