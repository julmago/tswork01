<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';
require_once __DIR__ . '/../include/site_stock_bulk.php';
require_once __DIR__ . '/../include/site_price_bulk.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
require_permission(hasPerm('sites_bulk_import_export'), 'Sin permiso para importar/exportar stock masivo.');
ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();
ensure_site_stock_bulk_schema();
ensure_site_price_bulk_schema();

function site_price_bulk_step_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$runId = (int)post('run_id', '0');
$limit = (int)post('limit', '200');
$limit = max(1, min(200, $limit));

if ($runId <= 0) {
  site_price_bulk_step_respond(['ok' => false, 'error' => 'run_id inválido.'], 422);
}

$pdo = db();

try {
  $stRun = $pdo->prepare('SELECT * FROM site_price_bulk_runs WHERE id = ? LIMIT 1');
  $stRun->execute([$runId]);
  $run = $stRun->fetch(PDO::FETCH_ASSOC);
  if (!$run) {
    site_price_bulk_step_respond(['ok' => false, 'error' => 'Run no encontrado.'], 404);
  }
  if ((string)$run['status'] === 'error') {
    site_price_bulk_step_respond(['ok' => true, 'run_id' => $runId, 'status' => 'error', 'processed_rows' => (int)$run['processed_rows'], 'total_rows' => (int)$run['total_rows'], 'last_error' => (string)($run['last_error'] ?? '')]);
  }

  $siteId = (int)$run['site_id'];
  $site = site_stock_bulk_load_site($pdo, $siteId);
  $channel = site_stock_bulk_channel((string)($site['channel_type'] ?? $site['site_channel_type'] ?? 'NONE'));
  if ($channel === 'NONE') {
    throw new RuntimeException('Sitio sin conexión configurada.');
  }

  $stRows = $pdo->prepare("SELECT id, sku, external_id, external_variant_id
    FROM site_price_bulk_rows
    WHERE run_id = ? AND status = 'PENDING'
    ORDER BY id ASC
    LIMIT ?");
  $stRows->bindValue(1, $runId, PDO::PARAM_INT);
  $stRows->bindValue(2, $limit, PDO::PARAM_INT);
  $stRows->execute();
  $rows = $stRows->fetchAll(PDO::FETCH_ASSOC);
  $startedAt = microtime(true);

  if (!$rows) {
    $stDone = $pdo->prepare("UPDATE site_price_bulk_runs SET status = CASE WHEN processed_rows >= total_rows THEN 'done' ELSE status END WHERE id = ?");
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
    $productsBySku = site_price_bulk_fetch_products_by_sku($pdo, $skuList);

    $processedInBatch = 0;
    foreach ($rows as $row) {
      if ((microtime(true) - $startedAt) > 1.5) {
        break;
      }
      $rowId = (int)$row['id'];
      $sku = trim((string)($row['sku'] ?? ''));
      $skuKey = mb_strtoupper($sku);
      $externalId = trim((string)($row['external_id'] ?? ''));
      $externalVariantId = trim((string)($row['external_variant_id'] ?? ''));
      $adjustmentPercent = (float)($run['adjustment_percent'] ?? 0);

      $status = 'OK';
      $message = 'Actualizado.';
      $tsPriceBefore = null;
      $finalPrice = null;
      $remotePriceBefore = null;
      $remotePriceAfter = null;

      if ($sku === '' || !isset($productsBySku[$skuKey])) {
        $status = 'SKIP';
        $message = 'SKU no existe en TSWork.';
      } else {
        $priceInfo = site_price_bulk_resolve_tswork_price($productsBySku[$skuKey], $site);
        $tsPriceBefore = $priceInfo['price'];
        if ($tsPriceBefore === null) {
          $status = 'SKIP';
          $message = (string)($priceInfo['reason'] ?? 'precio vacío');
        } elseif ((float)$tsPriceBefore <= 0) {
          $status = 'SKIP';
          $message = (float)$tsPriceBefore === 0.0 ? 'precio cero' : 'precio menor o igual a cero';
        } else {
          $finalPrice = site_price_bulk_calculate_final((float)$tsPriceBefore, $adjustmentPercent);
          $remotePriceAfter = $finalPrice;
          if ($channel === 'PRESTASHOP') {
            $setResult = site_price_bulk_ps_set_price($site, (int)$externalId, (int)$externalVariantId, $finalPrice);
          } else {
            $setResult = site_price_bulk_ml_set_price($siteId, $externalId, $externalVariantId, $finalPrice);
          }
          $status = !empty($setResult['ok']) ? 'OK' : 'ERROR';
          $message = (string)($setResult['message'] ?? '');
        }
      }

      $stUpdate = $pdo->prepare('UPDATE site_price_bulk_rows
        SET status = ?, message = ?, ts_price_before = ?, adjustment_percent = ?, final_price = ?, remote_price_before = ?, remote_price_after = ?
        WHERE id = ? AND run_id = ?');
      $stUpdate->execute([
        $status,
        $message,
        $tsPriceBefore !== null ? site_price_bulk_format_decimal((float)$tsPriceBefore) : null,
        site_price_bulk_format_decimal($adjustmentPercent),
        $finalPrice !== null ? site_price_bulk_format_decimal((float)$finalPrice) : null,
        $remotePriceBefore !== null ? site_price_bulk_format_decimal((float)$remotePriceBefore) : null,
        $remotePriceAfter !== null ? site_price_bulk_format_decimal((float)$remotePriceAfter) : null,
        $rowId,
        $runId,
      ]);
      $processedInBatch++;
    }

    if ($processedInBatch > 0) {
      $stProgress = $pdo->prepare("UPDATE site_price_bulk_runs SET processed_rows = processed_rows + ?, status = 'running' WHERE id = ?");
      $stProgress->execute([$processedInBatch, $runId]);
    }

    $stFinalize = $pdo->prepare("UPDATE site_price_bulk_runs SET status = CASE WHEN processed_rows >= total_rows THEN 'done' ELSE 'running' END WHERE id = ?");
    $stFinalize->execute([$runId]);
  }

  $stRun2 = $pdo->prepare('SELECT id, status, total_rows, processed_rows, last_error FROM site_price_bulk_runs WHERE id = ? LIMIT 1');
  $stRun2->execute([$runId]);
  $run2 = $stRun2->fetch(PDO::FETCH_ASSOC) ?: [];

  $stLast = $pdo->prepare('SELECT sku, status, ts_price_before, adjustment_percent, final_price, remote_price_after, message
    FROM site_price_bulk_rows
    WHERE run_id = ? AND status <> \'PENDING\'
    ORDER BY id DESC
    LIMIT 50');
  $stLast->execute([$runId]);
  $latestRows = array_reverse($stLast->fetchAll(PDO::FETCH_ASSOC) ?: []);

  site_price_bulk_step_respond([
    'ok' => true,
    'run_id' => $runId,
    'status' => (string)($run2['status'] ?? 'running'),
    'processed_rows' => (int)($run2['processed_rows'] ?? 0),
    'total_rows' => (int)($run2['total_rows'] ?? 0),
    'last_error' => (string)($run2['last_error'] ?? ''),
    'rows' => $latestRows,
  ]);
} catch (Throwable $t) {
  $stErr = $pdo->prepare("UPDATE site_price_bulk_runs SET status = 'error', last_error = ? WHERE id = ?");
  $stErr->execute([$t->getMessage(), $runId]);
  site_price_bulk_step_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
