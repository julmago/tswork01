<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';
require_once __DIR__ . '/../include/site_stock_bulk.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();
ensure_site_stock_bulk_schema();

function bulk_step_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function bulk_step_debug_suffix(array $run): string {
  $phase = trim((string)($run['debug_last_phase'] ?? ''));
  $offset = (int)($run['debug_last_offset'] ?? 0);
  $count = (int)($run['debug_last_count'] ?? 0);
  if ($phase === '' && $offset <= 0 && $count <= 0) {
    return '';
  }
  return ' [phase=' . ($phase !== '' ? $phase : 'unknown') . ', offset=' . $offset . ', count=' . $count . ']';
}

$runId = (int)post('run_id', '0');
$limit = (int)post('limit', '200');
$limit = max(1, min(200, $limit));

if ($runId <= 0) {
  bulk_step_respond(['ok' => false, 'error' => 'run_id inválido.'], 422);
}

$pdo = db();

try {
  $stRun = $pdo->prepare('SELECT * FROM site_stock_bulk_runs WHERE id = ? LIMIT 1');
  $stRun->execute([$runId]);
  $run = $stRun->fetch();
  if (!$run) {
    bulk_step_respond(['ok' => false, 'error' => 'Run no encontrado.'], 404);
  }

  if ((string)$run['status'] === 'error') {
    bulk_step_respond(['ok' => true, 'run_id' => $runId, 'status' => 'error', 'processed_rows' => (int)$run['processed_rows'], 'total_rows' => (int)$run['total_rows'], 'last_error' => (string)($run['last_error'] ?? '')]);
  }

  $stPhase = $pdo->prepare('UPDATE site_stock_bulk_runs SET debug_last_phase = ?, debug_last_offset = ?, debug_last_count = ? WHERE id = ?');
  $stPhase->execute(['apply_rows', (int)$run['processed_rows'], 0, $runId]);

  $siteId = (int)$run['site_id'];
  $site = site_stock_bulk_load_site($pdo, $siteId);
  $channel = site_stock_bulk_channel((string)($site['channel_type'] ?? $site['site_channel_type'] ?? 'NONE'));
  if ($channel === 'NONE') {
    throw new RuntimeException('Sitio sin conexión configurada.');
  }

  $stRows = $pdo->prepare("SELECT id, sku, remote_qty, external_id, external_variant_id
    FROM site_stock_bulk_rows
    WHERE run_id = ? AND status = 'PENDING'
    ORDER BY id ASC
    LIMIT ?");
  $stRows->bindValue(1, $runId, PDO::PARAM_INT);
  $stRows->bindValue(2, $limit, PDO::PARAM_INT);
  $stRows->execute();
  $rows = $stRows->fetchAll();
  $stPhase->execute(['apply_rows', (int)$run['processed_rows'], is_array($rows) ? count($rows) : 0, $runId]);
  $startedAt = microtime(true);

  if (!is_array($rows) || count($rows) === 0) {
    $stDone = $pdo->prepare("UPDATE site_stock_bulk_runs SET status = CASE WHEN processed_rows >= total_rows THEN 'done' ELSE status END WHERE id = ?");
    $stDone->execute([$runId]);
  } else {
    $skuList = [];
    foreach ($rows as $row) {
      $skuKey = mb_strtoupper(trim((string)($row['sku'] ?? '')));
      if ($skuKey !== '') {
        $skuList[] = $skuKey;
      }
    }
    $skuList = array_values(array_unique($skuList));

    $productsBySku = [];
    if (count($skuList) > 0) {
      $placeholders = implode(',', array_fill(0, count($skuList), '?'));
      $sqlProducts = "SELECT p.id, p.sku, COALESCE(ps.qty, 0) AS qty
        FROM products p
        LEFT JOIN ts_product_stock ps ON ps.product_id = p.id
        WHERE UPPER(TRIM(p.sku)) IN ($placeholders)";
      $stProducts = $pdo->prepare($sqlProducts);
      $stProducts->execute($skuList);
      $products = $stProducts->fetchAll();
      foreach ($products as $product) {
        $key = mb_strtoupper(trim((string)($product['sku'] ?? '')));
        if ($key === '') {
          continue;
        }
        if (!isset($productsBySku[$key])) {
          $productsBySku[$key] = [];
        }
        $productsBySku[$key][] = [
          'id' => (int)$product['id'],
          'qty' => site_stock_bulk_to_int($product['qty'] ?? 0),
        ];
      }
    }

    $processedInBatch = 0;
    foreach ($rows as $row) {
      if ((microtime(true) - $startedAt) > 1.5) {
        break;
      }
      $rowId = (int)$row['id'];
      $sku = trim((string)($row['sku'] ?? ''));
      $skuKey = mb_strtoupper($sku);
      $remoteQty = site_stock_bulk_to_int($row['remote_qty'] ?? 0);
      $externalId = trim((string)($row['external_id'] ?? ''));
      $externalVariantId = trim((string)($row['external_variant_id'] ?? ''));

      if ($sku === '' || !isset($productsBySku[$skuKey]) || count($productsBySku[$skuKey]) === 0) {
        $stUpdateRow = $pdo->prepare("UPDATE site_stock_bulk_rows
          SET status = 'SKIP', message = 'SKU no existe en TSWork.', ts_qty_before = NULL, ts_qty_after = NULL, remote_qty_before = ?, remote_qty_after = ?
          WHERE id = ? AND run_id = ?");
        $stUpdateRow->execute([$remoteQty, $remoteQty, $rowId, $runId]);
        $processedInBatch++;
        continue;
      }

      $tsProduct = $productsBySku[$skuKey][0];
      $tsQtyBefore = (int)$tsProduct['qty'];
      $tsQtyAfter = $tsQtyBefore;
      $remoteQtyBefore = $remoteQty;
      $remoteQtyAfter = $remoteQty;
      $rowStatus = 'OK';
      $message = '';

      if ((string)$run['action'] === 'import') {
        $tsQtyAfter = (string)$run['mode'] === 'add' ? $tsQtyBefore + $remoteQty : $remoteQty;
        try {
          set_stock((int)$tsProduct['id'], $tsQtyAfter, 'Bulk import run ' . $runId . ' SKU ' . $sku, 0, strtolower($channel), $siteId, null, 'sync_pull_bulk');
          $productsBySku[$skuKey][0]['qty'] = $tsQtyAfter;
          $message = 'TSWork actualizado.';
        } catch (Throwable $t) {
          $rowStatus = 'ERROR';
          $message = $t->getMessage();
        }
      } else {
        $remoteQtyAfter = (string)$run['mode'] === 'add' ? $remoteQtyBefore + $tsQtyBefore : $tsQtyBefore;
        if ($channel === 'PRESTASHOP') {
          $setResult = site_stock_bulk_ps_set_stock($site, (int)$externalId, (int)$externalVariantId, $remoteQtyAfter);
        } else {
          $setResult = site_stock_bulk_ml_set_stock($siteId, $externalId, $externalVariantId, $remoteQtyAfter);
        }
        $rowStatus = !empty($setResult['ok']) ? 'OK' : 'ERROR';
        $message = (string)($setResult['message'] ?? '');
      }

      $stUpdateRow = $pdo->prepare('UPDATE site_stock_bulk_rows
        SET status = ?, message = ?, ts_qty_before = ?, ts_qty_after = ?, remote_qty_before = ?, remote_qty_after = ?
        WHERE id = ? AND run_id = ?');
      $stUpdateRow->execute([$rowStatus, $message, $tsQtyBefore, $tsQtyAfter, $remoteQtyBefore, $remoteQtyAfter, $rowId, $runId]);
      $processedInBatch++;
    }

    if ($processedInBatch > 0) {
      $stProgress = $pdo->prepare('UPDATE site_stock_bulk_runs SET processed_rows = processed_rows + ?, status = \'running\' WHERE id = ?');
      $stProgress->execute([$processedInBatch, $runId]);
    }

    $stFinalize = $pdo->prepare("UPDATE site_stock_bulk_runs SET status = CASE WHEN processed_rows >= total_rows THEN 'done' ELSE 'running' END WHERE id = ?");
    $stFinalize->execute([$runId]);
  }

  $stRun2 = $pdo->prepare('SELECT id, status, total_rows, processed_rows, last_error FROM site_stock_bulk_runs WHERE id = ? LIMIT 1');
  $stRun2->execute([$runId]);
  $run2 = $stRun2->fetch();

  $stLast = $pdo->prepare('SELECT sku, status, ts_qty_before, ts_qty_after, remote_qty_before, remote_qty_after, message
    FROM site_stock_bulk_rows
    WHERE run_id = ? AND status <> \'PENDING\'
    ORDER BY id DESC
    LIMIT 50');
  $stLast->execute([$runId]);
  $latestRows = array_reverse($stLast->fetchAll() ?: []);

  bulk_step_respond([
    'ok' => true,
    'run_id' => $runId,
    'status' => (string)($run2['status'] ?? 'running'),
    'processed_rows' => (int)($run2['processed_rows'] ?? 0),
    'total_rows' => (int)($run2['total_rows'] ?? 0),
    'last_error' => (string)($run2['last_error'] ?? ''),
    'rows' => $latestRows,
  ]);
} catch (Throwable $t) {
  $stRunErr = $pdo->prepare('SELECT debug_last_phase, debug_last_offset, debug_last_count FROM site_stock_bulk_runs WHERE id = ? LIMIT 1');
  $stRunErr->execute([$runId]);
  $debugRun = $stRunErr->fetch() ?: [];
  $lastError = $t->getMessage() . bulk_step_debug_suffix(is_array($debugRun) ? $debugRun : []);
  $stErr = $pdo->prepare("UPDATE site_stock_bulk_runs SET status = 'error', last_error = ? WHERE id = ?");
  $stErr->execute([$lastError, $runId]);
  bulk_step_respond(['ok' => false, 'error' => $lastError], 500);
}
