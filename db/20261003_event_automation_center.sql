-- VNV Events automation center: policy, transactional outbox, execution health and user preferences.
-- Idempotent and scoped by owner/site. All timestamps in these tables are UTC.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS automation_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  master_enabled TINYINT(1) NOT NULL DEFAULT 0,
  dry_run TINYINT(1) NOT NULL DEFAULT 1,
  contract_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
  payment_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
  team_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
  rewards_messages_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ai_copy_enabled TINYINT(1) NOT NULL DEFAULT 0,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
  quiet_hours_start TIME NOT NULL DEFAULT '20:00:00',
  quiet_hours_end TIME NOT NULL DEFAULT '09:00:00',
  max_messages_per_user_day SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  max_messages_per_user_week SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  contract_cadence_days SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  payment_cadence_days SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  rewards_cadence_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  promotion_note VARCHAR(500) NULL,
  test_recipient_email VARCHAR(191) NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_automation_settings_scope (id_owner,site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO automation_settings (id_owner,site_key,master_enabled,dry_run)
VALUES (2,'vnvevents',0,1)
ON DUPLICATE KEY UPDATE id=id;

CREATE TABLE IF NOT EXISTS automation_user_preferences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  operational_enabled TINYINT(1) NOT NULL DEFAULT 1,
  marketing_enabled TINYINT(1) NOT NULL DEFAULT 1,
  preferred_hour TINYINT UNSIGNED NULL,
  timezone VARCHAR(64) NULL,
  last_engaged_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_automation_user_scope (id_owner,site_key,id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NULL,
  channel ENUM('EMAIL','PUSH','IN_APP') NOT NULL,
  message_type VARCHAR(64) NOT NULL,
  dedupe_key VARCHAR(191) NOT NULL,
  recipient VARCHAR(255) NULL,
  subject VARCHAR(255) NULL,
  body TEXT NOT NULL,
  action_url VARCHAR(700) NULL,
  payload_json LONGTEXT NULL,
  status ENUM('PENDING','PROCESSING','SENT','FAILED','CANCELLED','SIMULATED') NOT NULL DEFAULT 'PENDING',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  provider_reference VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_automation_outbox_dedupe (id_owner,site_key,dedupe_key,channel),
  KEY idx_automation_outbox_worker (status,available_at,attempts),
  KEY idx_automation_outbox_user (id_owner,site_key,id_user,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  job_key VARCHAR(80) NOT NULL,
  run_token CHAR(36) NOT NULL,
  trigger_type ENUM('CRON','MANUAL','TEST') NOT NULL DEFAULT 'CRON',
  status ENUM('RUNNING','SUCCESS','PARTIAL','FAILED','SKIPPED') NOT NULL DEFAULT 'RUNNING',
  scanned_count INT UNSIGNED NOT NULL DEFAULT 0,
  queued_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  details_json LONGTEXT NULL,
  error_message VARCHAR(1000) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_automation_run_token (run_token),
  KEY idx_automation_run_health (id_owner,site_key,job_key,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE automation_outbox
  MODIFY status ENUM('AWAITING_APPROVAL','PENDING','PROCESSING','SENT','FAILED','CANCELLED','SIMULATED') NOT NULL DEFAULT 'AWAITING_APPROVAL',
  ADD COLUMN IF NOT EXISTS reviewed_by INT NULL AFTER provider_reference,
  ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD COLUMN IF NOT EXISTS reviewer_comment VARCHAR(1000) NULL AFTER reviewed_at;

CREATE TABLE IF NOT EXISTS automation_reviewers (
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_owner,site_key,id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO automation_reviewers (id_owner,site_key,id_user)
SELECT 2,'vnvevents',id FROM users
WHERE id_owner=2 AND level=4 AND LOWER(email)='contact@vnvevents.com';

CREATE TABLE IF NOT EXISTS automation_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  thread_key VARCHAR(191) NOT NULL,
  id_customer INT NULL,
  id_author INT NULL,
  author_type ENUM('AGENT','HUMAN','SYSTEM') NOT NULL,
  message TEXT NOT NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_automation_conversation_thread (id_owner,site_key,thread_key,created_at),
  KEY idx_automation_conversation_customer (id_owner,site_key,id_customer,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_customer_memory (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_customer INT NOT NULL,
  memory_type ENUM('FACT','PREFERENCE','SENSITIVITY','INFERENCE') NOT NULL,
  summary VARCHAR(1000) NOT NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
  source_conversation_id BIGINT UNSIGNED NULL,
  status ENUM('ACTIVE','CORRECTED','EXPIRED','DELETED') NOT NULL DEFAULT 'ACTIVE',
  expires_at DATETIME NULL,
  created_by INT NULL,
  corrected_by INT NULL,
  correction_note VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_automation_memory_customer (id_owner,site_key,id_customer,status,expires_at),
  CONSTRAINT fk_automation_memory_conversation FOREIGN KEY (source_conversation_id) REFERENCES automation_conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'VNV automation center schema ready' AS result;
