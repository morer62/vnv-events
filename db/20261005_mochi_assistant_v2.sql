-- Mochi Assistant: daily sessions, role prompts and read-only tool observability.
-- Idempotent. Run after db/20261003_event_automation_center.sql.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mochi_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  session_date DATE NOT NULL,
  sequence_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  title VARCHAR(180) NOT NULL DEFAULT 'Conversacion diaria',
  status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mochi_daily_session (id_owner,site_key,id_user,session_date,sequence_no),
  KEY idx_mochi_user_history (id_owner,site_key,id_user,session_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE automation_conversations
  ADD COLUMN IF NOT EXISTS id_mochi_session BIGINT UNSIGNED NULL AFTER thread_key,
  ADD INDEX IF NOT EXISTS idx_automation_mochi_session (id_mochi_session,id);

CREATE TABLE IF NOT EXISTS mochi_suggested_prompts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  user_level TINYINT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  prompt VARCHAR(500) NOT NULL,
  icon VARCHAR(40) NOT NULL DEFAULT 'sparkles',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mochi_prompt (id_owner,site_key,user_level,title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mochi_tool_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  id_session BIGINT UNSIGNED NULL,
  tool_key VARCHAR(80) NOT NULL,
  status ENUM('SUCCESS','DENIED','FAILED') NOT NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mochi_tool_health (id_owner,site_key,tool_key,created_at),
  KEY idx_mochi_tool_user (id_user,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mochi_suggested_prompts (id_owner,site_key,user_level,title,prompt,icon,sort_order) VALUES
(2,'vnvevents',1,'Eventos del fin de semana','Muéstrame todos los eventos de este fin de semana y marca los que no tienen gerente asignado.','calendar',10),
(2,'vnvevents',1,'Cobros prioritarios','¿Cuáles son los saldos pendientes más altos que debo priorizar hoy?','wallet',20),
(2,'vnvevents',1,'Oportunidades','¿Qué estimados o solicitudes debo priorizar para seguimiento hoy?','chart',30),
(2,'vnvevents',1,'Productos y paquetes','¿Qué productos y paquetes puedo ofrecerle hoy a un cliente?','store',40),
(2,'vnvevents',4,'Mis eventos','¿Cuáles son mis próximos eventos asignados?','calendar',10),
(2,'vnvevents',4,'Mis tareas','¿Qué tareas tengo pendientes para mis eventos?','checklist',20),
(2,'vnvevents',4,'Horario','¿Cuál es mi agenda de trabajo de esta semana?','clock',30),
(2,'vnvevents',4,'Catálogo','¿Qué productos y servicios ofrece VNV Events?','store',40),
(2,'vnvevents',5,'Mis eventos','¿Cuáles son mis eventos activos?','calendar',10),
(2,'vnvevents',5,'Mis puntos','¿Cuántos puntos disponibles tengo y cuánto valen?','gift',20),
(2,'vnvevents',5,'Gourmet Express','¿Qué puedo ordenar hoy en VNV Gourmet Express?','store',30),
(2,'vnvevents',5,'Servicios VNV','¿Qué servicios ofrece VNV Events para mi evento?','sparkles',40)
ON DUPLICATE KEY UPDATE prompt=VALUES(prompt),icon=VALUES(icon),sort_order=VALUES(sort_order),status='ACTIVE';

SELECT 'Mochi Assistant schema ready' AS result;
