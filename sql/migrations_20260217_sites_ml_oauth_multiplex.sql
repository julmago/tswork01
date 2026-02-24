ALTER TABLE site_connections
  ADD COLUMN IF NOT EXISTS ml_token_expires_at DATETIME NULL AFTER ml_refresh_token,
  ADD COLUMN IF NOT EXISTS ml_connected_at DATETIME NULL AFTER ml_token_expires_at,
  ADD COLUMN IF NOT EXISTS ml_status VARCHAR(20) NOT NULL DEFAULT 'DISCONNECTED' AFTER ml_user_id;
