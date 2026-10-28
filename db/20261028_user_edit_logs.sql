-- Audit trail required by UserEditService after profile, role, rate or contract changes.
-- The write is intentionally separate from the user update, so this table must
-- exist before the edit form redirects back to the user list.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_edit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `edited_by` INT NULL,
  `changes` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_edit_logs_user_created` (`user_id`,`created_at`),
  KEY `idx_user_edit_logs_editor_created` (`edited_by`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
