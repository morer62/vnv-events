-- Enforce the product rule: one Mochi conversation per user and business day.
-- Existing duplicates must be backed up and reconciled before this migration.
SET NAMES utf8mb4;

ALTER TABLE mochi_sessions
  DROP INDEX uq_mochi_daily_session,
  ADD UNIQUE KEY uq_mochi_one_session_per_day (id_owner,site_key,id_user,session_date);

SELECT 'Mochi one-session-per-day constraint ready' AS result;
