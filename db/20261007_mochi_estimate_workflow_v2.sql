-- Mochi Estimate Workflow 2.0: durable conversational drafts and context.
-- Idempotent. Run after db/20261006_mochi_write_actions.sql.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mochi_estimate_workflows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  id_session BIGINT UNSIGNED NOT NULL,
  mode ENUM('CREATE','MODIFY') NOT NULL DEFAULT 'CREATE',
  status ENUM('COLLECTING','REVIEW','ACTIVE','COMPLETED','CANCELLED') NOT NULL DEFAULT 'COLLECTING',
  draft_json LONGTEXT NOT NULL,
  current_estimate_id INT NULL,
  last_error_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mochi_estimate_session (id_owner,site_key,id_user,id_session),
  KEY idx_mochi_estimate_current (id_owner,current_estimate_id,status),
  KEY idx_mochi_estimate_updated (id_owner,site_key,id_user,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM mochi_suggested_prompts
WHERE id_owner=2 AND site_key='vnvevents' AND user_level=1
  AND title IN ('Create an Estimate','Modify an Estimate');

INSERT INTO mochi_suggested_prompts
  (id_owner,site_key,user_level,title,prompt,icon,sort_order,status)
VALUES
  (2,'vnvevents',1,'Crear estimate','Necesito crear un estimate.','file-invoice-dollar',1,'ACTIVE'),
  (2,'vnvevents',1,'Modificar estimate','Necesito modificar un estimate existente.','pen-to-square',2,'ACTIVE')
ON DUPLICATE KEY UPDATE
  prompt=VALUES(prompt),icon=VALUES(icon),sort_order=VALUES(sort_order),status='ACTIVE';

UPDATE mochi_suggested_prompts
SET sort_order=CASE title
  WHEN 'Eventos del fin de semana' THEN 10
  WHEN 'Cobros prioritarios' THEN 20
  WHEN 'Productos y paquetes' THEN 30
  ELSE sort_order
END
WHERE id_owner=2 AND site_key='vnvevents' AND user_level=1;

SELECT 'Mochi Estimate Workflow 2.0 schema ready' AS result;
