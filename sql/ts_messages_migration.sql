ALTER TABLE ts_messages
  ADD COLUMN title VARCHAR(160) NOT NULL AFTER entity_id,
  ADD COLUMN thread_id INT UNSIGNED NULL AFTER title,
  ADD COLUMN parent_id INT UNSIGNED NULL AFTER thread_id,
  ADD COLUMN assigned_to_user_id INT UNSIGNED NULL AFTER created_by,
  ADD KEY idx_ts_messages_assigned (assigned_to_user_id, created_at),
  ADD KEY idx_ts_messages_thread (thread_id, created_at),
  ADD KEY idx_ts_messages_parent (parent_id),
  ADD CONSTRAINT fk_ts_messages_assigned_to_user
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_ts_messages_thread
    FOREIGN KEY (thread_id) REFERENCES ts_messages(id)
    ON DELETE SET NULL,
  ADD CONSTRAINT fk_ts_messages_parent
    FOREIGN KEY (parent_id) REFERENCES ts_messages(id)
    ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS ts_message_recipients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_message_recipients (message_id, user_id),
  KEY idx_ts_message_recipients_user (user_id, created_at),
  CONSTRAINT fk_ts_message_recipients_message
    FOREIGN KEY (message_id) REFERENCES ts_messages(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ts_message_recipients_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
  ADD COLUMN last_tasks_seen_at DATETIME NULL AFTER created_at;
