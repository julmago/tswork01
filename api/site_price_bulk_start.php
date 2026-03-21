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

function site_price_bulk_start_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$siteId = (int)post('site_id', '0');
$adjustmentPercentRaw = trim((string)post('adjustment_percent', ''));
$adjustmentPercent = site_price_bulk_normalize_percent($adjustmentPercentRaw);
if ($siteId <= 0) {
  site_price_bulk_start_respond(['ok' => false, 'error' => 'site_id inválido.'], 422);
}
if ($adjustmentPercentRaw !== '' && site_price_bulk_to_float($adjustmentPercentRaw) === null) {
  site_price_bulk_start_respond(['ok' => false, 'error' => 'Ajuste inválido.'], 422);
}

$pdo = db();
$runId = 0;

try {
  $site = site_stock_bulk_load_site($pdo, $siteId);
  $channel = site_stock_bulk_channel((string)($site['channel_type'] ?? $site['site_channel_type'] ?? 'NONE'));
  if ($channel === 'NONE') {
    site_price_bulk_start_respond(['ok' => false, 'error' => 'Sitio sin conexión configurada.'], 422);
  }

  $pdo->beginTransaction();
  $stRun = $pdo->prepare("INSERT INTO site_price_bulk_runs (site_id, adjustment_percent, status) VALUES (?, ?, 'running')");
  $stRun->execute([$siteId, site_price_bulk_format_decimal($adjustmentPercent)]);
  $runId = (int)$pdo->lastInsertId();
  $pdo->commit();

  $snapshotResult = $channel === 'PRESTASHOP'
    ? site_stock_bulk_ps_snapshot($site, $pdo, 0)
    : site_stock_bulk_ml_snapshot($pdo, $site, $siteId);

  $snapshotRows = is_array($snapshotResult['rows'] ?? null) ? $snapshotResult['rows'] : [];
  $snapshotErrors = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), (array)($snapshotResult['errors'] ?? [])), static fn(string $v): bool => $v !== ''));

  $pdo->beginTransaction();
  site_price_bulk_insert_snapshot_rows($pdo, $runId, $snapshotRows, $adjustmentPercent);
  $stCount = $pdo->prepare('SELECT COUNT(*) FROM site_price_bulk_rows WHERE run_id = ?');
  $stCount->execute([$runId]);
  $totalRows = (int)$stCount->fetchColumn();

  if ($totalRows === 0 && count($snapshotErrors) > 0) {
    $lastError = implode(' | ', array_slice($snapshotErrors, 0, 3));
    $stUpdate = $pdo->prepare("UPDATE site_price_bulk_runs SET total_rows = 0, processed_rows = 0, status = 'error', last_error = ? WHERE id = ?");
    $stUpdate->execute([$lastError, $runId]);
    $pdo->commit();
    site_price_bulk_start_respond(['ok' => false, 'run_id' => $runId, 'status' => 'error', 'error' => $lastError, 'last_error' => $lastError], 500);
  }

  $status = $totalRows === 0 ? 'done' : 'running';
  $stUpdate = $pdo->prepare("UPDATE site_price_bulk_runs SET total_rows = ?, processed_rows = 0, status = ?, last_error = NULL WHERE id = ?");
  $stUpdate->execute([$totalRows, $status, $runId]);
  $pdo->commit();

  site_price_bulk_start_respond([
    'ok' => true,
    'run_id' => $runId,
    'status' => $status,
    'total_rows' => $totalRows,
    'processed_rows' => 0,
  ]);
} catch (Throwable $t) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  if ($runId > 0) {
    $stErr = $pdo->prepare("UPDATE site_price_bulk_runs SET status = 'error', last_error = ? WHERE id = ?");
    $stErr->execute([$t->getMessage(), $runId]);
  }
  site_price_bulk_start_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
