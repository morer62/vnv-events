-- Reusable written-content Social Media Agent for Miami Tech Lab and VNV Events.
-- No credentials are stored here. Safe to rerun.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS social_editorial_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trigger_type ENUM('MANUAL','SCHEDULE','SYSTEM') NOT NULL DEFAULT 'MANUAL',
  mode ENUM('AUTO_PUBLISH','REVIEW_BEFORE_PUBLISH') NOT NULL DEFAULT 'REVIEW_BEFORE_PUBLISH',
  status ENUM('RUNNING','AWAITING_REVIEW','COMPLETED','PARTIAL','FAILED') NOT NULL DEFAULT 'RUNNING',
  summary_json LONGTEXT NULL,
  error_message TEXT NULL,
  created_by INT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_social_editorial_runs_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_editorial_candidates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  brand_key VARCHAR(64) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  article_id INT NOT NULL,
  article_url VARCHAR(500) NOT NULL,
  title VARCHAR(255) NOT NULL,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ranking_reason VARCHAR(500) NULL,
  selected TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_social_candidate_run_article (run_id,brand_key,article_id),
  KEY idx_social_candidate_selected (run_id,brand_key,selected,score),
  CONSTRAINT fk_social_candidate_run FOREIGN KEY (run_id) REFERENCES social_editorial_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_editorial_publications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  candidate_id BIGINT UNSIGNED NOT NULL,
  brand_key VARCHAR(64) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  article_id INT NOT NULL,
  article_url VARCHAR(500) NOT NULL,
  network ENUM('linkedin','facebook') NOT NULL,
  format ENUM('standard_page_post','page_article','newsletter_edition','link_post') NOT NULL,
  state ENUM('DISCOVERED','REVIEWED','SELECTED','PREPARED','SCHEDULED','PUBLISHED','FAILED','SKIPPED') NOT NULL DEFAULT 'PREPARED',
  headline VARCHAR(255) NULL,
  copy_text TEXT NOT NULL,
  hashtags_json TEXT NULL,
  image_url VARCHAR(500) NULL,
  cta_type ENUM('LEARN','DISCUSS','READ','CONTACT','EXPLORE','REGISTER') NOT NULL DEFAULT 'READ',
  scheduled_for DATETIME NULL,
  published_at DATETIME NULL,
  external_post_id VARCHAR(255) NULL,
  external_post_url VARCHAR(500) NULL,
  retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_social_article_network (brand_key,article_id,network,format),
  KEY idx_social_publication_due (state,scheduled_for),
  KEY idx_social_publication_history (brand_key,network,created_at),
  CONSTRAINT fk_social_publication_run FOREIGN KEY (run_id) REFERENCES social_editorial_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_social_publication_candidate FOREIGN KEY (candidate_id) REFERENCES social_editorial_candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_agents
  (id_owner,site_key,agent_key,name,description,category,status,approval_mode,schedule_enabled,schedule_expression,settings_json)
VALUES
  (2,'miamitechlab','social_publisher','Social Publisher','Prepare and publish approved Miami Tech Lab Facebook and LinkedIn Page posts.','content','ACTIVE','ALWAYS',0,NULL,'{"mode":"REVIEW_BEFORE_PUBLISH"}'),
  (2,'vnvevents','social_publisher','Social Publisher','Prepare and publish approved VNV Events Facebook and LinkedIn Page posts.','content','ACTIVE','ALWAYS',0,NULL,'{"mode":"REVIEW_BEFORE_PUBLISH"}')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),category=VALUES(category);

SELECT 'social_editorial_runs' entity,COUNT(*) total FROM social_editorial_runs
UNION ALL SELECT 'social_editorial_candidates',COUNT(*) FROM social_editorial_candidates
UNION ALL SELECT 'social_editorial_publications',COUNT(*) FROM social_editorial_publications;
