<?php

declare(strict_types=1);

$logPath = __DIR__ . '/../logs/ml_webhook_test.log';
$line = '[' . date('Y-m-d H:i:s') . "] ml_webhook_test ping\n";
$dir = dirname($logPath);

if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
  error_log('[ML_WEBHOOK_TEST] no se pudo crear directorio logs: ' . $dir);
  error_log('[ML_WEBHOOK_TEST] ' . trim($line));
} else {
  $ok = @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
  if ($ok === false) {
    error_log('[ML_WEBHOOK_TEST] no se pudo escribir log de prueba: ' . $logPath);
    error_log('[ML_WEBHOOK_TEST] ' . trim($line));
  }
}

header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";
