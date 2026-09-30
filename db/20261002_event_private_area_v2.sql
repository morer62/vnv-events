-- VNV Event Private Area: team tips, request threads, QR/share support and richer queues.
-- Idempotent. Run after db/event_execution_area_required.sql.
SET NAMES utf8mb4;

ALTER TABLE event_execution_spaces
  ADD COLUMN IF NOT EXISTS tips_days_after SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER interaction_days_after;

ALTER TABLE event_execution_music_requests
  ADD COLUMN IF NOT EXISTS youtube_url VARCHAR(700) NULL AFTER artist_name,
  ADD COLUMN IF NOT EXISTS staff_reply VARCHAR(500) NULL AFTER dedication,
  ADD COLUMN IF NOT EXISTS decline_reason VARCHAR(300) NULL AFTER staff_reply,
  MODIFY status ENUM(
    'PENDING','ACCEPTED','PLAYING_SOON','PLAYED','DECLINED','NEED_MORE_INFO',
    'WAITING','UP_NEXT','SINGING','QUEUED','PLAYING','COMPLETED','CANCELLED'
  ) NOT NULL DEFAULT 'PENDING';

CREATE TABLE IF NOT EXISTS event_execution_request_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_music_request INT UNSIGNED NOT NULL,
  id_user INT NOT NULL,
  message VARCHAR(700) NULL,
  youtube_url VARCHAR(700) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_request_comment_thread (id_music_request,created_at),
  CONSTRAINT fk_event_request_comment_request FOREIGN KEY (id_music_request) REFERENCES event_execution_music_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE event_execution_tip_payments
  MODIFY id_music_request INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS recipient_user_id INT UNSIGNED NULL AFTER id_user,
  ADD COLUMN IF NOT EXISTS tip_type ENUM('DJ_REQUEST','TEAM_MEMBER') NOT NULL DEFAULT 'DJ_REQUEST' AFTER recipient_user_id,
  ADD COLUMN IF NOT EXISTS sender_note VARCHAR(500) NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS saved_payment_method_id INT UNSIGNED NULL AFTER sender_note,
  ADD COLUMN IF NOT EXISTS refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER status,
  ADD INDEX IF NOT EXISTS idx_event_execution_tip_recipient (id_space,recipient_user_id,status,paid_at);

SELECT 'VNV Event Private Area 2.0 schema ready' AS result;
