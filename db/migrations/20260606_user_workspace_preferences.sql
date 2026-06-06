CREATE TABLE IF NOT EXISTS user_workspace_preferences (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  workspace_type ENUM('BUSINESS_OWNER','TEAM_MEMBER','CLIENT') NOT NULL DEFAULT 'CLIENT',
  selected_owner_id INT NULL,
  selected_institution_id INT NULL,
  selected_role VARCHAR(80) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_user_workspace_preference (user_id),
  KEY idx_user_workspace_selected_owner (selected_owner_id),
  KEY idx_user_workspace_selected_institution (selected_institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
