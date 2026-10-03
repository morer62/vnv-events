-- Mochi service/catalog workflow: durable, reviewed service and draft-store creation.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mochi_service_workflows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  id_session BIGINT UNSIGNED NOT NULL,
  status ENUM('COLLECTING','REVIEW_SERVICE','ASK_STORE','REVIEW_STORE','COMPLETED','CANCELLED') NOT NULL DEFAULT 'COLLECTING',
  draft_json LONGTEXT NOT NULL,
  current_service_id INT NULL,
  current_product_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_mochi_service_session(id_owner,site_key,id_user,id_session),
  KEY idx_mochi_service_current(id_owner,current_service_id,current_product_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mochi_suggested_prompts
  (id_owner,site_key,user_level,title,prompt,icon,sort_order,status)
VALUES
  (2,'vnvevents',1,'Agregar o modificar servicio','Quiero agregar o modificar un servicio.','wand-magic-sparkles',3,'ACTIVE')
ON DUPLICATE KEY UPDATE prompt=VALUES(prompt),icon=VALUES(icon),sort_order=3,status='ACTIVE';

UPDATE mochi_suggested_prompts SET sort_order=CASE title
  WHEN 'Crear estimate' THEN 1 WHEN 'Modificar estimate' THEN 2
  WHEN 'Agregar o modificar servicio' THEN 3
  WHEN 'Eventos del fin de semana' THEN 10 WHEN 'Cobros prioritarios' THEN 20
  WHEN 'Productos y paquetes' THEN 30 ELSE sort_order END
WHERE id_owner=2 AND site_key='vnvevents' AND user_level=1;

SELECT 'Mochi service/catalog workflow ready' AS result;
