<?php

declare(strict_types=1);

$logPath = __DIR__ . '/logs/ml_webhook.log';
$dir = dirname($logPath);
if (!is_dir($dir)) {
  @mkdir($dir, 0775, true);
}

$line = sprintf(
  "[%s] dev_ping_webhook OK ip=%s uri=%s\n",
  date('Y-m-d H:i:s'),
  (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
  (string)($_SERVER['REQUEST_URI'] ?? '-')
);

$ok = @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
header('Content-Type: text/plain; charset=utf-8');

if ($ok === false) {
  http_response_code(500);
  echo "ERROR writing log\n";
  exit;
}

echo "OK ping webhook\n";
