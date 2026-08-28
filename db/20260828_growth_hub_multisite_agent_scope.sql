-- Growth Hub / AI Agents multi-site isolation.
-- Existing owner 2 records belong to VNV Events and are backfilled accordingly.
-- Safe to execute before enabling Miami Tech Lab or The Pasta Station agents.

SET NAMES utf8mb4;

ALTER TABLE ai_agents
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_runs
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_approvals
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_connections
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_media_jobs
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_media_assets
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_video_revisions
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_conversations
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_editorial_plans
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_usage_logs
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;
ALTER TABLE ai_agent_approval_executions
  ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner;

UPDATE ai_agents SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_runs SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_approvals SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_connections SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_media_jobs SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_media_assets SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_video_revisions SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_conversations SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_editorial_plans SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_usage_logs SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';
UPDATE ai_agent_approval_executions SET site_key='vnvevents' WHERE site_key IS NULL OR TRIM(site_key)='';

DELIMITER $$
DROP PROCEDURE IF EXISTS vnv_growth_hub_multisite_indexes$$
CREATE PROCEDURE vnv_growth_hub_multisite_indexes()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='cms_routes' AND index_name='uk_cms_routes_route_owner_lang'
  ) THEN
    ALTER TABLE cms_routes DROP INDEX uk_cms_routes_route_owner_lang;
  END IF;
  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agents' AND index_name='uniq_ai_agents_owner_key'
  ) THEN
    ALTER TABLE ai_agents DROP INDEX uniq_ai_agents_owner_key;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agents' AND index_name='uniq_ai_agents_owner_site_key'
  ) THEN
    ALTER TABLE ai_agents ADD UNIQUE KEY uniq_ai_agents_owner_site_key (id_owner,site_key,agent_key);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agent_connections' AND index_name='uniq_ai_agent_connection'
  ) THEN
    ALTER TABLE ai_agent_connections DROP INDEX uniq_ai_agent_connection;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agent_connections' AND index_name='uniq_ai_agent_connection_site'
  ) THEN
    ALTER TABLE ai_agent_connections ADD UNIQUE KEY uniq_ai_agent_connection_site (id_owner,site_key,id_agent,platform);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agent_conversations' AND index_name='uq_ai_conversation_identity'
  ) THEN
    ALTER TABLE ai_agent_conversations DROP INDEX uq_ai_conversation_identity;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agent_conversations' AND index_name='uq_ai_conversation_identity_site'
  ) THEN
    ALTER TABLE ai_agent_conversations ADD UNIQUE KEY uq_ai_conversation_identity_site (id_owner,site_key,channel,external_user_id);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agent_editorial_plans' AND index_name='uq_ai_editorial_owner_name'
  ) THEN
    ALTER TABLE ai_agent_editorial_plans DROP INDEX uq_ai_editorial_owner_name;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='ai_agent_editorial_plans' AND index_name='uq_ai_editorial_owner_site_name'
  ) THEN
    ALTER TABLE ai_agent_editorial_plans ADD UNIQUE KEY uq_ai_editorial_owner_site_name (id_owner,site_key,name);
  END IF;
END$$
CALL vnv_growth_hub_multisite_indexes()$$
DROP PROCEDURE vnv_growth_hub_multisite_indexes$$
DELIMITER ;

ALTER TABLE ai_agent_runs ADD INDEX IF NOT EXISTS idx_ai_runs_owner_site (id_owner,site_key,status,created_at);
ALTER TABLE ai_agent_approvals ADD INDEX IF NOT EXISTS idx_ai_approvals_owner_site (id_owner,site_key,status,created_at);
ALTER TABLE ai_agent_media_jobs ADD INDEX IF NOT EXISTS idx_ai_media_owner_site (id_owner,site_key,status,created_at);
ALTER TABLE ai_agent_media_assets ADD INDEX IF NOT EXISTS idx_ai_assets_owner_site (id_owner,site_key,asset_type);
ALTER TABLE ai_agent_video_revisions ADD INDEX IF NOT EXISTS idx_ai_revisions_owner_site (id_owner,site_key,id_media_job);
ALTER TABLE ai_agent_usage_logs ADD INDEX IF NOT EXISTS idx_ai_usage_owner_site (id_owner,site_key,created_at);
ALTER TABLE ai_agent_approval_executions ADD INDEX IF NOT EXISTS idx_ai_execution_owner_site (id_owner,site_key,id_approval);

SELECT site_key,COUNT(*) AS agents
FROM ai_agents
GROUP BY site_key
ORDER BY site_key;
