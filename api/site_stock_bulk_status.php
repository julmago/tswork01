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

function bulk_status_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$runId = (int)get('run_id', '0');
if ($runId <= 0) {
  bulk_status_respond(['ok' => false, 'error' => 'run_id inválido.'], 422);
}

$pdo = db();
$stRun = $pdo->prepare('SELECT id, status, total_rows, processed_rows, last_error, debug_last_phase, debug_last_offset, debug_last_count FROM site_stock_bulk_runs WHERE id = ? LIMIT 1');
$stRun->execute([$runId]);
$run = $stRun->fetch();
if (!$run) {
  bulk_status_respond(['ok' => false, 'error' => 'Run no encontrado.'], 404);
}

$stRows = $pdo->prepare('SELECT sku, status, ts_qty_before, ts_qty_after, remote_qty_before, remote_qty_after, message
  FROM site_stock_bulk_rows
  WHERE run_id = ? AND status <> \'PENDING\'
  ORDER BY id DESC
  LIMIT 50');
$stRows->execute([$runId]);
$rows = array_reverse($stRows->fetchAll() ?: []);

bulk_status_respond([
  'ok' => true,
  'run_id' => $runId,
  'status' => (string)$run['status'],
  'processed_rows' => (int)$run['processed_rows'],
  'total_rows' => (int)$run['total_rows'],
  'last_error' => (string)($run['last_error'] ?? ''),
  'debug_last_phase' => (string)($run['debug_last_phase'] ?? ''),
  'debug_last_offset' => (int)($run['debug_last_offset'] ?? 0),
  'debug_last_count' => (int)($run['debug_last_count'] ?? 0),
  'rows' => $rows,
]);
