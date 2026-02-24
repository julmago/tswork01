<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock_sync.php';

require_login();
ensure_sites_schema();
ensure_stock_sync_schema();

header('Content-Type: application/json; charset=utf-8');

$siteId = (int)($_REQUEST['site_id'] ?? 0);
if ($siteId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'site_id inválido']);
  exit;
}

$action = trim((string)($_REQUEST['action'] ?? 'status'));

if ($action === 'recreate') {
  $callbackUrl = rtrim(base_url(), '/') . '/ml_webhook.php';
  $result = stock_sync_ml_register_default_subscriptions($siteId, $callbackUrl);
  $status = stock_sync_ml_subscription_status($siteId);
  echo json_encode(['ok' => (bool)($result['ok'] ?? false), 'recreate' => $result, 'status' => $status], JSON_UNESCAPED_UNICODE);
  exit;
}

$status = stock_sync_ml_subscription_status($siteId);
echo json_encode($status, JSON_UNESCAPED_UNICODE);
