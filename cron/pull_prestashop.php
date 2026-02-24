<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock_sync.php';

ensure_stock_sync_schema();

echo json_encode([
  'ok' => true,
  'message' => 'Modo pull de PrestaShop no implementado: usar webhook /api/inbound/prestashop_stock.php para sincronización de stock entrante.'
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
