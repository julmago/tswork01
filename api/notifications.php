<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
require_login();

function json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function api_action(): string {
  $action = post('action');
  if ($action === '') {
    $action = get('action');
  }
  if ($action !== '') {
    return $action;
  }
  $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  $base = BASE_PATH;
  if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
  }
  $path = trim($path, '/');
  $segments = $path === '' ? [] : explode('/', $path);
  if (count($segments) >= 3 && $segments[0] === 'api' && $segments[1] === 'notifications') {
    return $segments[2];
  }
  return '';
}

function require_csrf(): void {
  $token = post('csrf_token');
  if ($token === '' || !csrf_is_valid($token)) {
    json_response(['ok' => false, 'error' => 'Token CSRF inválido.'], 403);
  }
}

$action = api_action();
$pdo = db();
$current = current_user();
$current_user_id = (int)($current['id'] ?? 0);

if ($action === 'mark_read') {
  if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Método inválido.'], 405);
  }
  require_csrf();
  $mark_all = post('mark_all');
  if ($mark_all === '1') {
    $st = $pdo->prepare('UPDATE ts_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
    $st->execute([$current_user_id]);
    json_response(['ok' => true]);
  }
  $notification_id = (int)post('notification_id', '0');
  if ($notification_id <= 0) {
    json_response(['ok' => false, 'error' => 'Notificación inválida.'], 400);
  }
  $st = $pdo->prepare('UPDATE ts_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
  $st->execute([$notification_id, $current_user_id]);
  json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Acción inválida.'], 400);
