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

function bulk_start_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function bulk_start_zero_debug_payload(array $debug): array {
  return [
    'debug_last_url' => trim((string)($debug['debug_last_url'] ?? '')),
    'debug_last_http' => (int)($debug['debug_last_http'] ?? 0),
    'debug_last_body_preview' => trim((string)($debug['debug_last_body_preview'] ?? '')),
    'debug_pages_tried' => (int)($debug['debug_pages_tried'] ?? 0),
    'debug_last_phase' => trim((string)($debug['debug_last_phase'] ?? '')),
    'debug_last_offset' => (int)($debug['debug_last_offset'] ?? 0),
    'debug_last_count' => (int)($debug['debug_last_count'] ?? 0),
  ];
}

$siteId = (int)post('site_id', '0');
$action = trim((string)post('action', ''));
$mode = trim((string)post('mode', ''));
if ($siteId <= 0) {
  bulk_start_respond(['ok' => false, 'error' => 'site_id inválido.'], 422);
}
if (!in_array($action, ['import', 'export'], true)) {
  bulk_start_respond(['ok' => false, 'error' => 'Acción inválida.'], 422);
}
if (!in_array($mode, ['set', 'add'], true)) {
  bulk_start_respond(['ok' => false, 'error' => 'Modo inválido.'], 422);
}

$pdo = db();
$runId = 0;

try {
  $site = site_stock_bulk_load_site($pdo, $siteId);
  $channel = site_stock_bulk_channel((string)($site['channel_type'] ?? $site['site_channel_type'] ?? 'NONE'));
  if ($channel === 'NONE') {
    bulk_start_respond(['ok' => false, 'error' => 'Sitio sin conexión configurada.'], 422);
  }

  $pdo->beginTransaction();
  $stRun = $pdo->prepare("INSERT INTO site_stock_bulk_runs (site_id, action, mode, status) VALUES (?, ?, ?, 'running')");
  $stRun->execute([$siteId, $action, $mode]);
  $runId = (int)$pdo->lastInsertId();
  $pdo->commit();

  $snapshotResult = $channel === 'PRESTASHOP'
    ? site_stock_bulk_ps_snapshot($site, $pdo, $runId)
    : site_stock_bulk_ml_snapshot($pdo, $site, $siteId);

  $snapshotRows = is_array($snapshotResult['rows'] ?? null) ? $snapshotResult['rows'] : [];
  $snapshotErrors = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), (array)($snapshotResult['errors'] ?? [])), static fn(string $v): bool => $v !== ''));
  $snapshotDebug = is_array($snapshotResult['debug'] ?? null) ? $snapshotResult['debug'] : [];
  $debugPayload = bulk_start_zero_debug_payload($snapshotDebug);
  $debugLastUrl = $debugPayload['debug_last_url'];
  $debugLastHttp = $debugPayload['debug_last_http'];
  $debugLastBodyPreview = $debugPayload['debug_last_body_preview'];
  $debugPagesTried = $debugPayload['debug_pages_tried'];
  $debugIsValidEmpty = (bool)($snapshotDebug['debug_is_valid_empty'] ?? false);

  $pdo->beginTransaction();
  site_stock_bulk_insert_snapshot_rows($pdo, $runId, $snapshotRows);
  $stCount = $pdo->prepare('SELECT COUNT(*) FROM site_stock_bulk_rows WHERE run_id = ?');
  $stCount->execute([$runId]);
  $totalRows = (int)$stCount->fetchColumn();

  $stDebug = $pdo->prepare('UPDATE site_stock_bulk_runs SET debug_last_url = ?, debug_last_http = ?, debug_last_body_preview = ?, debug_pages_tried = ?, debug_last_phase = ?, debug_last_offset = ?, debug_last_count = ? WHERE id = ?');
  $stDebug->execute([
    $debugLastUrl,
    $debugLastHttp > 0 ? $debugLastHttp : null,
    $debugLastBodyPreview,
    max(0, $debugPagesTried),
    $debugPayload['debug_last_phase'] !== '' ? $debugPayload['debug_last_phase'] : null,
    max(0, $debugPayload['debug_last_offset']),
    max(0, $debugPayload['debug_last_count']),
    $runId,
  ]);

  if ($totalRows === 0 && count($snapshotErrors) > 0) {
    $lastError = implode(' | ', array_slice($snapshotErrors, 0, 3));
    $stUpdate = $pdo->prepare("UPDATE site_stock_bulk_runs SET total_rows = 0, processed_rows = 0, status = 'error', last_error = ? WHERE id = ?");
    $stUpdate->execute([$lastError, $runId]);
    $pdo->commit();
    bulk_start_respond([
      'ok' => false,
      'run_id' => $runId,
      'status' => 'error',
      'total_rows' => 0,
      'processed_rows' => 0,
      'error' => $lastError,
      'last_error' => $lastError,
      'debug' => $debugPayload,
    ], 500);
  }

  if ($totalRows === 0) {
    $status = 'done';
    $lastError = null;
    if ($debugLastHttp !== 200) {
      $status = 'error';
      $previewPart = $debugLastBodyPreview !== '' ? ' · ' . $debugLastBodyPreview : '';
      $lastError = 'HTTP ' . ($debugLastHttp > 0 ? $debugLastHttp : 0) . $previewPart;
    } elseif (!$debugIsValidEmpty || $debugLastBodyPreview === '') {
      $status = 'error';
      $previewPart = $debugLastBodyPreview !== '' ? ': ' . $debugLastBodyPreview : '';
      $lastError = 'Respuesta inesperada' . $previewPart;
    }

    if ($status === 'error') {
      $stUpdate = $pdo->prepare("UPDATE site_stock_bulk_runs SET total_rows = 0, processed_rows = 0, status = 'error', last_error = ? WHERE id = ?");
      $stUpdate->execute([$lastError, $runId]);
      $pdo->commit();
      bulk_start_respond([
        'ok' => false,
        'run_id' => $runId,
        'status' => 'error',
        'total_rows' => 0,
        'processed_rows' => 0,
        'error' => $lastError,
        'last_error' => $lastError,
        'debug' => $debugPayload,
      ], 500);
    }

    $stUpdate = $pdo->prepare("UPDATE site_stock_bulk_runs SET total_rows = 0, processed_rows = 0, status = 'done', last_error = NULL WHERE id = ?");
    $stUpdate->execute([$runId]);
    $pdo->commit();
    bulk_start_respond([
      'ok' => true,
      'run_id' => $runId,
      'status' => 'done',
      'total_rows' => 0,
      'processed_rows' => 0,
      'debug' => $debugPayload,
    ]);
  }

  $stUpdate = $pdo->prepare("UPDATE site_stock_bulk_runs SET total_rows = ?, processed_rows = 0, status = 'running', last_error = NULL WHERE id = ?");
  $stUpdate->execute([$totalRows, $runId]);
  $pdo->commit();

  bulk_start_respond([
    'ok' => true,
    'run_id' => $runId,
    'status' => 'running',
    'total_rows' => $totalRows,
    'processed_rows' => 0,
  ]);
} catch (Throwable $t) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  if ($runId > 0) {
    $stErr = $pdo->prepare("UPDATE site_stock_bulk_runs SET status = 'error', last_error = ? WHERE id = ?");
    $stErr->execute([$t->getMessage(), (int)$runId]);
  }
  bulk_start_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
