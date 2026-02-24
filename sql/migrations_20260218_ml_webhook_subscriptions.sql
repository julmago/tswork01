ALTER TABLE site_connections
  ADD COLUMN IF NOT EXISTS ml_app_id VARCHAR(100) NULL AFTER ml_client_id,
  ADD COLUMN IF NOT EXISTS ml_notification_callback_url VARCHAR(255) NULL AFTER ml_notification_secret,
  ADD COLUMN IF NOT EXISTS ml_subscription_id VARCHAR(120) NULL AFTER ml_user_id,
  ADD COLUMN IF NOT EXISTS ml_subscription_topic VARCHAR(50) NULL AFTER ml_subscription_id,
  ADD COLUMN IF NOT EXISTS ml_subscription_updated_at DATETIME NULL AFTER ml_subscription_topic;

UPDATE site_connections
SET ml_app_id = ml_client_id
WHERE COALESCE(ml_app_id, '') = '';
