CREATE TABLE IF NOT EXISTS ts_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type ENUM('product','listado','pedido','proveedor','user') NOT NULL,
  entity_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  thread_id INT UNSIGNED NULL,
  parent_id INT UNSIGNED NULL,
  message_type ENUM('observacion','problema','consulta','accion') NOT NULL DEFAULT 'observacion',
  status ENUM('abierto','en_proceso','resuelto','archivado') NOT NULL DEFAULT 'abierto',
  body TEXT NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  archived_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ts_messages_entity (entity_type, entity_id, created_at),
  KEY idx_ts_messages_status (status, created_at),
  KEY idx_ts_messages_created_by (created_by, created_at),
  KEY idx_ts_messages_assigned (assigned_to_user_id, created_at),
  KEY idx_ts_messages_thread (thread_id, created_at),
  KEY idx_ts_messages_parent (parent_id),
  CONSTRAINT fk_ts_messages_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_ts_messages_assigned_to_user
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_ts_messages_thread
    FOREIGN KEY (thread_id) REFERENCES ts_messages(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_ts_messages_parent
    FOREIGN KEY (parent_id) REFERENCES ts_messages(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS ts_message_mentions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  mentioned_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_message_mentions (message_id, mentioned_user_id),
  KEY idx_ts_message_mentions_user (mentioned_user_id, created_at),
  CONSTRAINT fk_ts_message_mentions_message
    FOREIGN KEY (message_id) REFERENCES ts_messages(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ts_message_mentions_user
    FOREIGN KEY (mentioned_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ts_notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('mention','assigned','system') NOT NULL DEFAULT 'mention',
  message_id INT UNSIGNED NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ts_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_ts_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ts_notifications_message
    FOREIGN KEY (message_id) REFERENCES ts_messages(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
