<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function setting_get(string $key, string $default=''): string {
  $st = db()->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
  $st->execute([$key]);
  $row = $st->fetch();
  if (!$row) return $default;
  return (string)$row['value'];
}

function setting_set(string $key, string $value): void {
  $st = db()->prepare("INSERT INTO settings(`key`,`value`) VALUES(?, ?)
    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
  $st->execute([$key, $value]);
}
