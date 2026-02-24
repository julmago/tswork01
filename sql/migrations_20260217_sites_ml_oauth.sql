ALTER TABLE site_connections
  ADD COLUMN IF NOT EXISTS ml_redirect_uri VARCHAR(255) NULL AFTER ml_client_secret,
  ADD COLUMN IF NOT EXISTS ml_access_token TEXT NULL AFTER ml_redirect_uri,
  ADD COLUMN IF NOT EXISTS ml_user_id VARCHAR(40) NULL AFTER ml_refresh_token,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER ml_user_id;
