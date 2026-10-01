-- Adds the customer-facing message used while VNV Gourmet Express is open.
-- Idempotent. Run after db/20261004_vnv_gourmet_express_master.sql.
SET NAMES utf8mb4;

ALTER TABLE store_gourmet_settings
  ADD COLUMN IF NOT EXISTS open_message VARCHAR(300) NULL AFTER store_paused;

UPDATE store_gourmet_settings
SET open_message='We are open and accepting VNV Gourmet Express orders.'
WHERE id_owner=2
  AND site_key='vnvevents'
  AND (open_message IS NULL OR TRIM(open_message)='');

SELECT 'VNV Gourmet Express open message ready' AS result;
