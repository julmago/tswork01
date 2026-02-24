<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_sync_schema();

function ml_unlink_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!is_post()) {
  ml_unlink_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$linkId = (int)post('link_id', '0');
if ($linkId <= 0) {
  ml_unlink_json(['ok' => false, 'error' => 'link_id inválido.'], 422);
}

try {
  $pdo = db();
  $st = $pdo->prepare('DELETE FROM ts_ml_links WHERE id = ? LIMIT 1');
  $st->execute([$linkId]);
  ml_unlink_json(['ok' => true, 'deleted' => $st->rowCount() > 0]);
} catch (Throwable $t) {
  ml_unlink_json(['ok' => false, 'error' => 'No se pudo desvincular.'], 500);
}
