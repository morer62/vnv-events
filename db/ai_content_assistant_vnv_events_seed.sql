-- Incremental seed for an already-installed AI Content Assistant schema.
-- Use this in the VNV Events repo when db/ai_content_assistant_required.sql
-- was installed before the VNV Events default profile was added.

INSERT INTO `ai_content_settings`
  (`id_owner`, `id_user_business`, `site_key`, `setting_key`, `setting_value`, `created_at`, `updated_at`)
VALUES
  (2, 2, 'vnv_events', 'enabled', '0', NOW(), NOW()),
  (2, 2, 'vnv_events', 'daily_blog_count', '1', NOW(), NOW()),
  (2, 2, 'vnv_events', 'daily_location_count', '5', NOW(), NOW()),
  (2, 2, 'vnv_events', 'auto_publish', '0', NOW(), NOW()),
  (2, 2, 'vnv_events', 'require_approval', '1', NOW(), NOW()),
  (2, 2, 'vnv_events', 'default_language', 'en', NOW(), NOW()),
  (2, 2, 'vnv_events', 'brand_name', 'VNV Events', NOW(), NOW()),
  (2, 2, 'vnv_events', 'priority_services', 'wedding planning, corporate events, social events, event rentals, catering coordination', NOW(), NOW()),
  (2, 2, 'vnv_events', 'priority_cities', 'Miami, Doral, Fort Lauderdale, Hollywood, Weston, Pembroke Pines, Coral Gables', NOW(), NOW()),
  (2, 2, 'vnv_events', 'location_state', 'FL', NOW(), NOW()),
  (2, 2, 'vnv_events', 'max_pending_drafts', '50', NOW(), NOW()),
  (2, 2, 'vnv_events', 'cloudinary_enabled', '1', NOW(), NOW()),
  (2, 2, 'vnv_events', 'reddit_sources_enabled', '0', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `updated_at` = NOW();
