<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function ensure_stock_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $pdo->exec("CREATE TABLE IF NOT EXISTS ts_product_stock (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    qty INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ts_product_stock_product (product_id),
    KEY idx_ts_product_stock_updated_by (updated_by),
    CONSTRAINT fk_ts_product_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ts_product_stock_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS ts_stock_moves (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    delta INT NOT NULL,
    stock_resultante INT NOT NULL DEFAULT 0,
    reason VARCHAR(50) NOT NULL DEFAULT 'ajuste',
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ts_stock_moves_product_created (product_id, created_at, id),
    KEY idx_ts_stock_moves_created_by (created_by),
    CONSTRAINT fk_ts_stock_moves_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ts_stock_moves_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $hasStockResultante = false;
  $st = $pdo->query("SHOW COLUMNS FROM ts_stock_moves LIKE 'stock_resultante'");
  if ($st) {
    $hasStockResultante = (bool)$st->fetch();
  }
  if (!$hasStockResultante) {
    $pdo->exec('ALTER TABLE ts_stock_moves ADD COLUMN stock_resultante INT NOT NULL DEFAULT 0 AFTER delta');
  }

  $hasOrigin = false;
  $st = $pdo->query("SHOW COLUMNS FROM ts_stock_moves LIKE 'origin'");
  if ($st) {
    $hasOrigin = (bool)$st->fetch();
  }
  if (!$hasOrigin) {
    $pdo->exec("ALTER TABLE ts_stock_moves ADD COLUMN origin ENUM('tswork','prestashop','mercadolibre') NOT NULL DEFAULT 'tswork' AFTER reason");
  }

  $hasEventId = false;
  $st = $pdo->query("SHOW COLUMNS FROM ts_stock_moves LIKE 'event_id'");
  if ($st) {
    $hasEventId = (bool)$st->fetch();
  }
  if (!$hasEventId) {
    $pdo->exec("ALTER TABLE ts_stock_moves ADD COLUMN event_id VARCHAR(120) NULL AFTER origin");
  }

  $ready = true;
}

function get_stock(int $product_id): array {
  ensure_stock_schema();

  $st = db()->prepare('SELECT id, product_id, qty, updated_at, updated_by FROM ts_product_stock WHERE product_id = ? LIMIT 1');
  $st->execute([$product_id]);
  $row = $st->fetch();

  if ($row) {
    return [
      'product_id' => (int)$row['product_id'],
      'qty' => (int)$row['qty'],
      'updated_at' => (string)$row['updated_at'],
      'updated_by' => isset($row['updated_by']) ? (int)$row['updated_by'] : null,
    ];
  }

  return [
    'product_id' => $product_id,
    'qty' => 0,
    'updated_at' => null,
    'updated_by' => null,
  ];
}

function set_stock(int $product_id, int $qty, ?string $note, int $user_id, string $origin = 'tswork', ?int $source_site_id = null, ?string $event_id = null, ?string $reason = 'carga_manual'): array {
  ensure_stock_schema();

  $origin = normalize_stock_origin($origin);
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT qty FROM ts_product_stock WHERE product_id = ? FOR UPDATE');
    $st->execute([$product_id]);
    $current = $st->fetch();
    $current_qty = $current ? (int)$current['qty'] : 0;
    $delta = $qty - $current_qty;

    if ($current) {
      $st = $pdo->prepare('UPDATE ts_product_stock SET qty = ?, updated_at = NOW(), updated_by = ? WHERE product_id = ?');
      $st->execute([$qty, $user_id > 0 ? $user_id : null, $product_id]);
    } else {
      $st = $pdo->prepare('INSERT INTO ts_product_stock(product_id, qty, updated_at, updated_by) VALUES(?, ?, NOW(), ?)');
      $st->execute([$product_id, $qty, $user_id > 0 ? $user_id : null]);
    }

    $st = $pdo->prepare('INSERT INTO ts_stock_moves(product_id, delta, stock_resultante, reason, origin, event_id, note, created_at, created_by) VALUES(?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    $st->execute([$product_id, $delta, $qty, normalize_stock_reason($reason, 'carga_manual'), $origin, normalize_stock_event_id($event_id), normalize_stock_note($note), $user_id > 0 ? $user_id : null]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  stock_sync_after_local_change($product_id, $qty, $origin, $source_site_id, $event_id);
  return get_stock($product_id);
}

function add_stock(int $product_id, int $delta, ?string $note, int $user_id, string $origin = 'tswork', ?int $source_site_id = null, ?string $event_id = null): array {
  ensure_stock_schema();

  $origin = normalize_stock_origin($origin);
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT qty FROM ts_product_stock WHERE product_id = ? FOR UPDATE');
    $st->execute([$product_id]);
    $current = $st->fetch();
    $current_qty = $current ? (int)$current['qty'] : 0;

    $new_qty = $current_qty + $delta;

    if ($current) {
      $st = $pdo->prepare('UPDATE ts_product_stock SET qty = ?, updated_at = NOW(), updated_by = ? WHERE product_id = ?');
      $st->execute([$new_qty, $user_id > 0 ? $user_id : null, $product_id]);
    } else {
      $st = $pdo->prepare('INSERT INTO ts_product_stock(product_id, qty, updated_at, updated_by) VALUES(?, ?, NOW(), ?)');
      $st->execute([$product_id, $new_qty, $user_id > 0 ? $user_id : null]);
    }

    $reason = $delta === 0 ? 'inventario' : 'ajuste';
    $st = $pdo->prepare('INSERT INTO ts_stock_moves(product_id, delta, stock_resultante, reason, origin, event_id, note, created_at, created_by) VALUES(?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    $st->execute([$product_id, $delta, $new_qty, $reason, $origin, normalize_stock_event_id($event_id), normalize_stock_note($note), $user_id > 0 ? $user_id : null]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  stock_sync_after_local_change($product_id, $new_qty, $origin, $source_site_id, $event_id);
  return get_stock($product_id);
}

function stock_sync_after_local_change(int $productId, int $qty, string $origin, ?int $sourceSiteId, ?string $eventId): void {
  if (!function_exists('enqueue_stock_push_jobs')) {
    require_once __DIR__ . '/stock_sync.php';
  }
  enqueue_stock_push_jobs($productId, $qty, $origin, $sourceSiteId, $eventId);
}

function get_stock_moves(int $product_id, int $limit = 20): array {
  ensure_stock_schema();
  $limit = max(1, min(100, $limit));

  $st = db()->prepare("SELECT m.id, m.delta, m.stock_resultante, m.reason, m.origin, m.event_id, m.note, m.created_at, m.created_by,
      CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS user_name,
      u.email AS user_email
    FROM ts_stock_moves m
    LEFT JOIN users u ON u.id = m.created_by
    WHERE m.product_id = ?
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT {$limit}");
  $st->execute([$product_id]);

  return $st->fetchAll();
}

function normalize_stock_note(?string $note): ?string {
  $value = trim((string)$note);
  if ($value === '') {
    return null;
  }
  return mb_substr($value, 0, 1000);
}

function normalize_stock_origin(string $origin): string {
  $value = strtolower(trim($origin));
  if (!in_array($value, ['tswork', 'prestashop', 'mercadolibre'], true)) {
    return 'tswork';
  }
  return $value;
}

function normalize_stock_event_id(?string $eventId): ?string {
  $value = trim((string)$eventId);
  if ($value === '') {
    return null;
  }
  return mb_substr($value, 0, 120);
}

function normalize_stock_reason(?string $reason, string $fallback = 'ajuste'): string {
  $value = trim((string)$reason);
  if ($value === '') {
    $value = $fallback;
  }
  return mb_substr($value, 0, 50);
}
