-- VNV Events private execution area (idempotent table creation).
CREATE TABLE IF NOT EXISTS event_execution_spaces (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_order INT NOT NULL,
  id_owner INT NOT NULL,
  access_code CHAR(5) NOT NULL,
  status ENUM('ACTIVE','CLOSED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_execution_order (id_order),
  UNIQUE KEY uq_event_execution_access_code (access_code),
  KEY idx_event_execution_code_status (access_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_execution_members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_space INT UNSIGNED NOT NULL,
  id_user INT NOT NULL,
  role ENUM('CLIENT','PARTICIPANT','TEAM','DJ','ADMIN') NOT NULL DEFAULT 'PARTICIPANT',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_execution_member (id_space, id_user),
  KEY idx_event_execution_member_user (id_user, id_space),
  CONSTRAINT fk_event_execution_member_space FOREIGN KEY (id_space) REFERENCES event_execution_spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_execution_music_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_space INT UNSIGNED NOT NULL,
  id_user INT NOT NULL,
  request_type ENUM('KARAOKE','SONG_REQUEST') NOT NULL,
  participant_name VARCHAR(120) NOT NULL,
  song_title VARCHAR(180) NOT NULL,
  artist_name VARCHAR(180) DEFAULT NULL,
  dedication VARCHAR(300) DEFAULT NULL,
  status ENUM('QUEUED','PLAYING','COMPLETED','CANCELLED') NOT NULL DEFAULT 'QUEUED',
  tip_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tip_status ENUM('NONE','PENDING','PROCESSING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'NONE',
  tip_transaction_id VARCHAR(190) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_execution_music_queue (id_space, request_type, status, sort_order, created_at),
  KEY idx_event_execution_music_user (id_user, id_space),
  CONSTRAINT fk_event_execution_music_space FOREIGN KEY (id_space) REFERENCES event_execution_spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_execution_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_space INT UNSIGNED NOT NULL,
  id_user INT NOT NULL,
  photo_url VARCHAR(700) NOT NULL,
  caption VARCHAR(240) DEFAULT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  deleted_at DATETIME DEFAULT NULL,
  deleted_by INT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_event_execution_photo_gallery (id_space, deleted_at, expires_at, id_user),
  CONSTRAINT fk_event_execution_photo_space FOREIGN KEY (id_space) REFERENCES event_execution_spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_execution_tip_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_space INT UNSIGNED NOT NULL,
  id_music_request INT UNSIGNED NOT NULL,
  id_user INT NOT NULL,
  id_owner INT NOT NULL,
  provider_type VARCHAR(30) NOT NULL,
  provider_payment_id VARCHAR(190) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'PAID',
  metadata_json LONGTEXT DEFAULT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_execution_tip_provider_payment (provider_type, provider_payment_id),
  KEY idx_event_execution_tip_request (id_music_request, status),
  KEY idx_event_execution_tip_owner_date (id_owner, paid_at),
  CONSTRAINT fk_event_execution_tip_space FOREIGN KEY (id_space) REFERENCES event_execution_spaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_execution_tip_request FOREIGN KEY (id_music_request) REFERENCES event_execution_music_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
