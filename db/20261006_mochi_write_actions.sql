-- Controlled Mochi write actions and immutable audit trail.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mochi_action_drafts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL, site_key VARCHAR(80) NOT NULL, id_user INT NOT NULL,
  id_session BIGINT UNSIGNED NULL, action_key VARCHAR(80) NOT NULL,
  payload_json LONGTEXT NOT NULL, summary VARCHAR(1000) NOT NULL,
  status ENUM('PENDING','CONFIRMED','EXECUTED','CANCELLED','FAILED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  expires_at DATETIME NOT NULL, confirmed_at DATETIME NULL, executed_at DATETIME NULL,
  result_json LONGTEXT NULL, error_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_mochi_action_pending(id_owner,site_key,id_user,status,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mochi_action_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL, site_key VARCHAR(80) NOT NULL, id_user INT NOT NULL,
  id_action_draft BIGINT UNSIGNED NULL, action_key VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL, entity_id VARCHAR(100) NULL,
  action_source VARCHAR(40) NOT NULL DEFAULT 'MOCHI', fields_json LONGTEXT NOT NULL,
  status ENUM('SUCCEEDED','FAILED','CANCELLED') NOT NULL, error_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_mochi_audit_entity(id_owner,site_key,entity_type,entity_id), KEY idx_mochi_audit_user(id_user,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_followups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, id_owner INT NOT NULL, id_customer INT NOT NULL,
  id_request BIGINT UNSIGNED NULL, id_order INT NULL, due_at DATETIME NOT NULL,
  subject VARCHAR(180) NOT NULL, notes TEXT NULL, status ENUM('OPEN','COMPLETED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_crm_followups_due(id_owner,status,due_at), KEY idx_crm_followups_customer(id_owner,id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Mochi write actions schema ready' AS result;
