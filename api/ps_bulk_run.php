<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../prestashop.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
if (!can_suppliers_ps_bulk()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Sin permisos.'], JSON_UNESCAPED_UNICODE);
  exit;
}

ensure_stock_sync_schema();

function ps_bulk_run_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function ensure_ps_bulk_run_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS ps_bulk_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT UNSIGNED NULL,
    site_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    total_estimated INT UNSIGNED NOT NULL DEFAULT 0,
    processed INT UNSIGNED NOT NULL DEFAULT 0,
    ok INT UNSIGNED NOT NULL DEFAULT 0,
    skipped INT UNSIGNED NOT NULL DEFAULT 0,
    not_found INT UNSIGNED NOT NULL DEFAULT 0,
    error INT UNSIGNED NOT NULL DEFAULT 0,
    next_offset INT UNSIGNED NOT NULL DEFAULT 0,
    batch_limit INT UNSIGNED NOT NULL DEFAULT 5,
    settings_json LONGTEXT NULL,
    last_error TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ps_bulk_runs_supplier (supplier_id, created_at),
    KEY idx_ps_bulk_runs_status (status, updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS ps_bulk_run_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sku_supplier VARCHAR(190) NULL,
    sku_tsw VARCHAR(190) NULL,
    ps_id INT NULL,
    action VARCHAR(120) NULL,
    result VARCHAR(30) NOT NULL DEFAULT 'PENDING',
    relink VARCHAR(255) NULL,
    request_url TEXT NULL,
    extra_json LONGTEXT NULL,
    PRIMARY KEY (id),
    KEY idx_ps_bulk_run_rows_run (run_id, id),
    KEY idx_ps_bulk_run_rows_result (run_id, result),
    CONSTRAINT fk_ps_bulk_run_rows_run FOREIGN KEY (run_id) REFERENCES ps_bulk_runs(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $ready = true;
}

function ps_bulk_detect_delimiter(string $line): string {
  $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
  arsort($counts);
  $top = array_key_first($counts);
  return $top !== null ? (string)$top : ',';
}

function ps_bulk_parse_csv_first_column(string $path): array {
  $rawLines = file($path, FILE_IGNORE_NEW_LINES);
  if (!is_array($rawLines) || count($rawLines) === 0) {
    return [];
  }
  $delimiter = ps_bulk_detect_delimiter((string)$rawLines[0]);
  $rows = [];
  foreach ($rawLines as $line) {
    if (trim($line) === '') {
      continue;
    }
    $cols = str_getcsv($line, $delimiter);
    $value = trim((string)($cols[0] ?? ''));
    if ($value !== '') {
      $rows[] = $value;
    }
  }
  if (count($rows) === 0) {
    return [];
  }
  $first = strtolower(trim($rows[0]));
  if (in_array($first, ['sku', 'supplier_sku', 'supplier sku', 'sku proveedor', 'supplier-sku'], true)) {
    array_shift($rows);
  }
  return $rows;
}

function ps_bulk_reference_candidates(array $item): array {
  $candidates = [];
  $tsw = trim((string)($item['sku'] ?? ''));
  $sup = trim((string)($item['supplier_sku'] ?? ''));
  if ($tsw !== '') {
    $candidates[] = $tsw;
  }
  if ($sup !== '' && $sup !== $tsw) {
    $candidates[] = $sup;
  }
  if ($sup !== '') {
    if (str_starts_with($sup, 'SS-')) {
      $without = substr($sup, 3);
      if ($without !== '' && !in_array($without, $candidates, true)) {
        $candidates[] = $without;
      }
    } else {
      $with = 'SS-' . $sup;
      if (!in_array($with, $candidates, true)) {
        $candidates[] = $with;
      }
    }
  }
  return $candidates;
}

function ps_bulk_resolve_product_id(array $item, string $baseUrl, string $apiKey, ?array &$testedCandidates = null): ?int {
  $candidates = ps_bulk_reference_candidates($item);
  $testedCandidates = $candidates;
  foreach ($candidates as $candidate) {
    $results = ps_find_by_reference_all($candidate, $baseUrl, $apiKey);
    if (empty($results)) {
      continue;
    }
    foreach ($results as $result) {
      if (($result['type'] ?? '') === 'product' && (int)($result['id_product'] ?? 0) > 0) {
        return (int)$result['id_product'];
      }
    }
    $id = (int)($results[0]['id_product'] ?? 0);
    if ($id > 0) {
      return $id;
    }
  }
  return null;
}

function ps_bulk_build_action_label(array $row): string {
  $activeBefore = (string)($row['active_before'] ?? '');
  $activeAfter = (string)($row['active_after'] ?? '');
  $outBefore = (string)($row['out_of_stock_before'] ?? '');
  $outAfter = (string)($row['out_of_stock_after'] ?? '');
  $changedActive = $activeBefore !== $activeAfter;
  $changedOut = $outBefore !== $outAfter;
  if ($changedActive && $changedOut) {
    return 'Ambos';
  }
  if ($changedActive) {
    return 'Active';
  }
  if ($changedOut) {
    return 'Stock';
  }
  return 'Sin cambios';
}

function ps_bulk_apply_product_changes(PDO $pdo, array $item, array $ctx, array &$notFoundInRun): array {
  $siteId = (int)$ctx['site_id'];
  $setActive = (int)$ctx['set_active'];
  $setOutOfStock = (int)$ctx['set_out_of_stock'];
  $scopeLabel = (string)$ctx['scope'];
  $baseUrl = (string)$ctx['ps_base_url'];
  $apiKey = (string)$ctx['ps_api_key'];
  $shopId = (int)$ctx['ps_shop_id'];
  $respectSku = !empty($ctx['respect_sku_manual_changes']);

  $productId = (int)($item['product_id'] ?? 0);
  if ($productId <= 0) {
    return ['status' => 'ERROR', 'row' => ['scope' => $scopeLabel, 'supplier_sku' => (string)($item['supplier_sku'] ?? ''), 'sku' => (string)($item['sku'] ?? ''), 'ps_product_id' => '', 'error' => 'product_id inválido']];
  }

  if (isset($notFoundInRun[$productId])) {
    return ['status' => 'NOT_FOUND', 'row' => ['scope' => $scopeLabel, 'supplier_sku' => (string)$item['supplier_sku'], 'sku' => (string)$item['sku'], 'ps_product_id' => '', 'error' => 'Sin reintento en esta corrida: id_product no resuelto previamente.', 'relink' => '']];
  }

  $remoteProductId = null;
  $mapSt = $pdo->prepare('SELECT remote_id FROM site_product_map WHERE site_id = ? AND product_id = ? LIMIT 1');
  $mapSt->execute([$siteId, $productId]);
  $mapRow = $mapSt->fetch();
  if ($mapRow) {
    $remoteProductId = (int)$mapRow['remote_id'];
  }

  $testedCandidates = [];
  if (!$remoteProductId) {
    $remoteProductId = ps_bulk_resolve_product_id($item, $baseUrl, $apiKey, $testedCandidates);
    if ($remoteProductId > 0) {
      $mapUpsertSt = $pdo->prepare("INSERT INTO site_product_map(site_id, product_id, remote_id, updated_at)
        VALUES(?,?,?,NOW())
        ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), updated_at = NOW()");
      $mapUpsertSt->execute([$siteId, $productId, (string)$remoteProductId]);
    }
  }

  if (!$remoteProductId) {
    $notFoundInRun[$productId] = true;
    $candidateText = implode(', ', $testedCandidates ?: ps_bulk_reference_candidates($item));
    return ['status' => 'NOT_FOUND', 'row' => ['scope' => $scopeLabel, 'supplier_sku' => (string)$item['supplier_sku'], 'sku' => (string)$item['sku'], 'ps_product_id' => '', 'error' => 'No existe en PrestaShop. Probé: ' . $candidateText, 'relink' => '']];
  }

  $expectedSku = trim((string)($item['sku'] ?? ''));
  if ($expectedSku === '') {
    $expectedSku = trim((string)($item['supplier_sku'] ?? ''));
  }

  $relinkMessage = '';
  if ($respectSku) {
    $referenceActual = ps_get_product_reference_with_credentials((int)$remoteProductId, $baseUrl, $apiKey, $shopId);
    if ($referenceActual !== $expectedSku) {
      $newRemoteProductId = ps_bulk_resolve_product_id($item, $baseUrl, $apiKey, $testedCandidates);
      if ($newRemoteProductId && (int)$newRemoteProductId !== (int)$remoteProductId) {
        $oldRemoteProductId = (int)$remoteProductId;
        $remoteProductId = (int)$newRemoteProductId;
        $relinkMessage = 'RELINK ' . $oldRemoteProductId . ' -> ' . $remoteProductId;
        $mapUpsertSt = $pdo->prepare("INSERT INTO site_product_map(site_id, product_id, remote_id, updated_at)
          VALUES(?,?,?,NOW())
          ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), updated_at = NOW()");
        $mapUpsertSt->execute([$siteId, $productId, (string)$remoteProductId]);
      } else {
        $candidateText = implode(', ', $testedCandidates ?: ps_bulk_reference_candidates($item));
        return ['status' => 'SKIPPED', 'row' => ['scope' => $scopeLabel, 'supplier_sku' => (string)$item['supplier_sku'], 'sku' => (string)$item['sku'], 'ps_product_id' => (string)$remoteProductId, 'error' => 'Referencia en PrestaShop fue modificada (actual=' . $referenceActual . ', esperado=' . $expectedSku . '). No se encontró relink automático. Probé: ' . $candidateText, 'relink' => '']];
      }
    }
  }

  $activeUpdateDebug = ps_update_product_active_with_credentials($remoteProductId, $setActive, $baseUrl, $apiKey, $shopId);
  $stockUpdateDebug = ps_update_product_out_of_stock_by_product_with_credentials($remoteProductId, $setOutOfStock, $baseUrl, $apiKey, $shopId);
  $row = [
    'scope' => $scopeLabel,
    'supplier_sku' => (string)$item['supplier_sku'],
    'sku' => (string)$item['sku'],
    'ps_product_id' => (string)$remoteProductId,
    'status' => 'OK',
    'error' => '',
    'relink' => $relinkMessage,
    'request_url' => (string)($activeUpdateDebug['url'] ?? ''),
    'active_before' => (string)($activeUpdateDebug['active_before'] ?? ''),
    'active_after' => (string)($activeUpdateDebug['active_after'] ?? ''),
    'out_of_stock_before' => (string)($stockUpdateDebug['out_of_stock_before'] ?? ''),
    'out_of_stock_after' => (string)($stockUpdateDebug['out_of_stock_after'] ?? ''),
  ];
  return ['status' => 'OK', 'row' => $row];
}

function ps_bulk_insert_result_row(PDO $pdo, int $runId, array $row): int {
  $actionLabel = ps_bulk_build_action_label($row);
  $status = strtoupper((string)($row['status'] ?? 'ERROR'));
  $st = $pdo->prepare('INSERT INTO ps_bulk_run_rows (run_id, sku_supplier, sku_tsw, ps_id, action, result, relink, request_url, extra_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
  $st->execute([
    $runId,
    (string)($row['supplier_sku'] ?? ''),
    (string)($row['sku'] ?? ''),
    (int)($row['ps_product_id'] ?? 0) ?: null,
    $actionLabel,
    $status,
    trim((string)($row['relink'] ?? '')),
    trim((string)($row['request_url'] ?? '')),
    json_encode($row, JSON_UNESCAPED_UNICODE),
  ]);
  return (int)$pdo->lastInsertId();
}

function ps_bulk_load_run(PDO $pdo, int $runId): array {
  $st = $pdo->prepare('SELECT * FROM ps_bulk_runs WHERE id = ? LIMIT 1');
  $st->execute([$runId]);
  $run = $st->fetch(PDO::FETCH_ASSOC);
  if (!$run) {
    throw new RuntimeException('Run no encontrado.');
  }
  return $run;
}

ensure_ps_bulk_run_schema();
$pdo = db();
$action = trim((string)post('action', ''));

try {
  if ($action === 'start') {
    $supplierId = (int)($_SESSION['ps_bulk_supplier_id'] ?? 0);
    $siteId = (int)($_SESSION['ps_bulk_site_id'] ?? 0);
    $csvPath = (string)($_SESSION['ps_bulk_csv_path'] ?? '');
    if ($supplierId <= 0 || $siteId <= 0 || $csvPath === '' || !is_file($csvPath)) {
      ps_bulk_run_respond(['ok' => false, 'error' => 'Sesión inválida o expirada.'], 422);
    }

    $siteSt = $pdo->prepare("SELECT s.id, s.name, s.conn_type, s.conn_enabled, sc.ps_base_url, sc.ps_api_key, sc.ps_shop_id
      FROM sites s
      INNER JOIN site_connections sc ON sc.site_id = s.id
      WHERE s.id = ? LIMIT 1");
    $siteSt->execute([$siteId]);
    $site = $siteSt->fetch(PDO::FETCH_ASSOC);
    if (!$site || strtolower((string)$site['conn_type']) !== 'prestashop' || (int)$site['conn_enabled'] !== 1) {
      ps_bulk_run_respond(['ok' => false, 'error' => 'Sitio PrestaShop inválido.'], 422);
    }

    $csvSkus = ps_bulk_parse_csv_first_column($csvPath);
    if (count($csvSkus) === 0) {
      ps_bulk_run_respond(['ok' => false, 'error' => 'El CSV no contiene SKUs válidos.'], 422);
    }

    $matchSt = $pdo->prepare('SELECT p.id, p.sku, p.name, ps.supplier_sku, ps.is_active
      FROM product_suppliers ps
      INNER JOIN products p ON p.id = ps.product_id
      WHERE ps.supplier_id = ? AND ps.supplier_sku = ?
      ORDER BY ps.is_active DESC, p.id ASC');

    $found = [];
    $foundRowKeys = [];
    foreach ($csvSkus as $skuProv) {
      $matchSt->execute([$supplierId, $skuProv]);
      foreach ($matchSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $productId = (int)$row['id'];
        $rowKey = $skuProv . '::' . $productId;
        if (isset($foundRowKeys[$rowKey])) {
          continue;
        }
        $found[] = [
          'include_key' => $rowKey,
          'product_id' => $productId,
          'supplier_sku' => (string)$row['supplier_sku'],
          'sku' => (string)$row['sku'],
          'name' => (string)$row['name'],
          'supplier_active' => (int)$row['is_active'] === 1,
        ];
        $foundRowKeys[$rowKey] = true;
      }
    }

    $selected = $_POST['include'] ?? [];
    if (!is_array($selected)) {
      $selected = [];
    }
    $includedItems = [];
    $includedProductIds = [];
    foreach ($found as $item) {
      $key = (string)$item['include_key'];
      if ($key === '' || !isset($selected[$key])) {
        continue;
      }
      $includedItems[] = $item;
      $includedProductIds[(int)$item['product_id']] = true;
    }

    $formApplyToNonListed = post('apply_to_non_listed', '0') === '1';
    $formRespectSkuManualChanges = post('respect_sku_manual_changes', '1') === '1';
    $offlineMode = post('offline_mode', 'online') === 'offline' ? 'offline' : 'online';
    $outMode = post('out_of_stock_mode', 'default');
    $nonListedActiveMode = post('non_listed_active_mode', 'en_linea') === 'fuera_linea' ? 'fuera_linea' : 'en_linea';
    $nonListedOutMode = post('non_listed_outofstock_mode', 'default');
    $batchLimit = max(1, min(50, (int)($_POST['step_limit'] ?? $_POST['non_listed_batch_limit'] ?? 5)));

    $includedIds = array_map(static fn($v): int => (int)$v, array_keys($includedProductIds));

    $nonListedTotal = 0;
    if ($formApplyToNonListed) {
      if (count($includedIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($includedIds), '?'));
        $stCount = $pdo->prepare("SELECT COUNT(DISTINCT ps.product_id)
          FROM product_suppliers ps
          WHERE ps.supplier_id = ? AND ps.product_id NOT IN ({$placeholders})");
        $stCount->execute(array_merge([$supplierId], $includedIds));
      } else {
        $stCount = $pdo->prepare('SELECT COUNT(DISTINCT ps.product_id) FROM product_suppliers ps WHERE ps.supplier_id = ?');
        $stCount->execute([$supplierId]);
      }
      $nonListedTotal = (int)$stCount->fetchColumn();
    }

    $activeValue = $offlineMode === 'offline' ? 0 : 1;
    $outOfStockValue = $outMode === 'deny' ? 0 : ($outMode === 'allow' ? 1 : 2);
    $nonListedActiveValue = $nonListedActiveMode === 'fuera_linea' ? 0 : 1;
    $nonListedOutValue = $nonListedOutMode === 'deny' ? 0 : ($nonListedOutMode === 'allow' ? 1 : 2);

    $settings = [
      'site_id' => $siteId,
      'supplier_id' => $supplierId,
      'ps_base_url' => trim((string)($site['ps_base_url'] ?? '')),
      'ps_api_key' => trim((string)($site['ps_api_key'] ?? '')),
      'ps_shop_id' => (int)($site['ps_shop_id'] ?? 0),
      'respect_sku_manual_changes' => $formRespectSkuManualChanges,
      'included_items' => $includedItems,
      'included_product_ids' => $includedIds,
      'included_active' => $activeValue,
      'included_out_of_stock' => $outOfStockValue,
      'apply_to_non_listed' => $formApplyToNonListed,
      'non_listed_active' => $nonListedActiveValue,
      'non_listed_out_of_stock' => $nonListedOutValue,
    ];

    $totalEstimated = count($includedItems) + $nonListedTotal;
    $userId = (int)(current_user()['id'] ?? 0);

    $stRun = $pdo->prepare("INSERT INTO ps_bulk_runs (user_id, site_id, supplier_id, status, total_estimated, processed, ok, skipped, not_found, error, next_offset, batch_limit, settings_json)
      VALUES (?, ?, ?, 'running', ?, 0, 0, 0, 0, 0, 0, ?, ?)");
    $stRun->execute([
      $userId > 0 ? $userId : null,
      $siteId,
      $supplierId,
      $totalEstimated,
      $batchLimit,
      json_encode($settings, JSON_UNESCAPED_UNICODE),
    ]);

    $runId = (int)$pdo->lastInsertId();
    ps_bulk_run_respond(['ok' => true, 'run_id' => $runId, 'done' => $totalEstimated === 0, 'processed' => 0, 'total_estimated' => $totalEstimated]);
  }

  if ($action === 'step') {
    $runId = (int)post('run_id', '0');
    $limit = max(1, min(20, (int)post('limit', '5')));
    if ($runId <= 0) {
      ps_bulk_run_respond(['ok' => false, 'error' => 'run_id inválido.'], 422);
    }

    $run = ps_bulk_load_run($pdo, $runId);
    if ((string)$run['status'] === 'done') {
      ps_bulk_run_respond(['ok' => true, 'done' => true, 'processed' => (int)$run['processed'], 'counters' => ['ok' => (int)$run['ok'], 'skipped' => (int)$run['skipped'], 'not_found' => (int)$run['not_found'], 'error' => (int)$run['error']], 'rows' => []]);
    }

    $settings = json_decode((string)($run['settings_json'] ?? '{}'), true);
    if (!is_array($settings)) {
      throw new RuntimeException('settings_json inválido.');
    }

    $processed = (int)$run['processed'];
    $includedItems = is_array($settings['included_items'] ?? null) ? $settings['included_items'] : [];
    $includedCount = count($includedItems);
    $includeProcessed = min($processed, $includedCount);
    $nextOffset = (int)$run['next_offset'];
    $processedThisStep = 0;
    $rowsPayload = [];
    $notFoundInRun = [];

    while ($processedThisStep < $limit) {
      $item = null;
      $ctx = [];

      if ($includeProcessed < $includedCount) {
        $item = $includedItems[$includeProcessed];
        $ctx = [
          'site_id' => (int)$settings['site_id'],
          'set_active' => (int)$settings['included_active'],
          'set_out_of_stock' => (int)$settings['included_out_of_stock'],
          'scope' => 'incluido CSV',
          'ps_base_url' => (string)$settings['ps_base_url'],
          'ps_api_key' => (string)$settings['ps_api_key'],
          'ps_shop_id' => (int)$settings['ps_shop_id'],
          'respect_sku_manual_changes' => !empty($settings['respect_sku_manual_changes']),
        ];
        $includeProcessed++;
      } elseif (!empty($settings['apply_to_non_listed'])) {
        $includedIds = is_array($settings['included_product_ids'] ?? null) ? array_map('intval', $settings['included_product_ids']) : [];
        if (count($includedIds) > 0) {
          $placeholders = implode(',', array_fill(0, count($includedIds), '?'));
          $sql = "SELECT p.id, p.sku, p.name, ps.supplier_sku
            FROM product_suppliers ps
            INNER JOIN products p ON p.id = ps.product_id
            WHERE ps.supplier_id = ? AND ps.product_id NOT IN ({$placeholders})
            GROUP BY p.id, p.sku, p.name, ps.supplier_sku
            ORDER BY p.id ASC
            LIMIT 1 OFFSET {$nextOffset}";
          $stItem = $pdo->prepare($sql);
          $stItem->execute(array_merge([(int)$settings['supplier_id']], $includedIds));
        } else {
          $sql = "SELECT p.id, p.sku, p.name, ps.supplier_sku
            FROM product_suppliers ps
            INNER JOIN products p ON p.id = ps.product_id
            WHERE ps.supplier_id = ?
            GROUP BY p.id, p.sku, p.name, ps.supplier_sku
            ORDER BY p.id ASC
            LIMIT 1 OFFSET {$nextOffset}";
          $stItem = $pdo->prepare($sql);
          $stItem->execute([(int)$settings['supplier_id']]);
        }
        $row = $stItem->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
          break;
        }
        $item = [
          'product_id' => (int)$row['id'],
          'supplier_sku' => (string)$row['supplier_sku'],
          'sku' => (string)$row['sku'],
          'name' => (string)$row['name'],
        ];
        $nextOffset++;
        $ctx = [
          'site_id' => (int)$settings['site_id'],
          'set_active' => (int)$settings['non_listed_active'],
          'set_out_of_stock' => (int)$settings['non_listed_out_of_stock'],
          'scope' => 'no incluido',
          'ps_base_url' => (string)$settings['ps_base_url'],
          'ps_api_key' => (string)$settings['ps_api_key'],
          'ps_shop_id' => (int)$settings['ps_shop_id'],
          'respect_sku_manual_changes' => !empty($settings['respect_sku_manual_changes']),
        ];
      } else {
        break;
      }

      if ($item === null) {
        break;
      }

      $result = ps_bulk_apply_product_changes($pdo, $item, $ctx, $notFoundInRun);
      $rowData = is_array($result['row'] ?? null) ? $result['row'] : [];
      $rowData['status'] = (string)($result['status'] ?? 'ERROR');
      $newRowId = ps_bulk_insert_result_row($pdo, $runId, $rowData);

      $status = strtoupper((string)$rowData['status']);
      if ($status === 'OK') {
        $run['ok'] = (int)$run['ok'] + 1;
      } elseif ($status === 'SKIPPED') {
        $run['skipped'] = (int)$run['skipped'] + 1;
      } elseif ($status === 'NOT_FOUND') {
        $run['not_found'] = (int)$run['not_found'] + 1;
      } else {
        $run['error'] = (int)$run['error'] + 1;
      }

      $run['processed'] = (int)$run['processed'] + 1;
      $processedThisStep++;
      $rowsPayload[] = [
        'id' => $newRowId,
        'sku_supplier' => (string)($rowData['supplier_sku'] ?? ''),
        'sku_tsw' => (string)($rowData['sku'] ?? ''),
        'ps_id' => (int)($rowData['ps_product_id'] ?? 0),
        'action' => ps_bulk_build_action_label($rowData),
        'result' => $status,
        'relink' => trim((string)($rowData['relink'] ?? '')),
        'request_url' => trim((string)($rowData['request_url'] ?? '')),
      ];
    }

    $done = (int)$run['processed'] >= (int)$run['total_estimated'] || $processedThisStep === 0;
    $newStatus = $done ? 'done' : 'running';
    $stUpdate = $pdo->prepare('UPDATE ps_bulk_runs SET status = ?, processed = ?, ok = ?, skipped = ?, not_found = ?, error = ?, next_offset = ? WHERE id = ?');
    $stUpdate->execute([
      $newStatus,
      (int)$run['processed'],
      (int)$run['ok'],
      (int)$run['skipped'],
      (int)$run['not_found'],
      (int)$run['error'],
      $nextOffset,
      $runId,
    ]);

    ps_bulk_run_respond([
      'ok' => true,
      'done' => $done,
      'processed' => (int)$run['processed'],
      'total_estimated' => (int)$run['total_estimated'],
      'counters' => [
        'ok' => (int)$run['ok'],
        'skipped' => (int)$run['skipped'],
        'not_found' => (int)$run['not_found'],
        'error' => (int)$run['error'],
      ],
      'rows' => $rowsPayload,
    ]);
  }

  if ($action === 'status') {
    $runId = (int)post('run_id', '0');
    $lastRowId = max(0, (int)post('last_row_id', '0'));
    if ($runId <= 0) {
      ps_bulk_run_respond(['ok' => false, 'error' => 'run_id inválido.'], 422);
    }
    $run = ps_bulk_load_run($pdo, $runId);
    $stRows = $pdo->prepare('SELECT id, sku_supplier, sku_tsw, ps_id, action, result, relink, request_url FROM ps_bulk_run_rows WHERE run_id = ? AND id > ? ORDER BY id ASC LIMIT 200');
    $stRows->execute([$runId, $lastRowId]);
    $rows = $stRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    ps_bulk_run_respond([
      'ok' => true,
      'done' => (string)$run['status'] === 'done',
      'status' => (string)$run['status'],
      'processed' => (int)$run['processed'],
      'total_estimated' => (int)$run['total_estimated'],
      'counters' => [
        'ok' => (int)$run['ok'],
        'skipped' => (int)$run['skipped'],
        'not_found' => (int)$run['not_found'],
        'error' => (int)$run['error'],
      ],
      'rows' => $rows,
    ]);
  }

  ps_bulk_run_respond(['ok' => false, 'error' => 'Acción inválida.'], 422);
} catch (Throwable $t) {
  $runId = (int)post('run_id', '0');
  if ($runId > 0) {
    $stErr = $pdo->prepare("UPDATE ps_bulk_runs SET status = 'error', last_error = ? WHERE id = ?");
    $stErr->execute([$t->getMessage(), $runId]);
  }
  ps_bulk_run_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
