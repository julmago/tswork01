<?php
declare(strict_types=1);

if (ob_get_level() === 0) {
  ob_start();
}

$config = require __DIR__ . '/config.php';
$auth = require __DIR__ . '/config/auth.php';

error_reporting(E_ALL);
ini_set('log_errors', '1');
$debug = (bool)($config['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');

function normalize_base_path(string $path): string {
  $path = trim($path);
  if ($path === '' || $path === '/') {
    return '';
  }
  if ($path[0] !== '/') {
    $path = '/' . $path;
  }
  return rtrim($path, '/');
}

function detect_base_path(): string {
  $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
  $projectRoot = realpath(__DIR__);
  if ($docRoot && $projectRoot) {
    $docRoot = str_replace('\\', '/', $docRoot);
    $projectRoot = str_replace('\\', '/', $projectRoot);
    if (str_starts_with($projectRoot, $docRoot)) {
      $relative = trim(substr($projectRoot, strlen($docRoot)), '/');
      return $relative === '' ? '' : '/' . $relative;
    }
  }
  $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  if ($scriptName === '') {
    return '';
  }
  $dir = str_replace('\\', '/', dirname($scriptName));
  if ($dir === '/' || $dir === '.') {
    return '';
  }
  return $dir;
}

$configuredBasePath = (string)($config['base_path'] ?? '');
$basePath = normalize_base_path($configuredBasePath !== '' ? $configuredBasePath : detect_base_path());
if (!defined('BASE_PATH')) {
  define('BASE_PATH', $basePath);
}
$configuredBaseUrl = (string)($config['base_url'] ?? '');
$baseUrl = normalize_base_path($configuredBaseUrl !== '' ? $configuredBaseUrl : $basePath);
if (!defined('BASE_URL')) {
  define('BASE_URL', $baseUrl);
}
if (!defined('APP_BASE')) {
  define('APP_BASE', BASE_URL);
}

$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
  mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php-error.log');

set_exception_handler(function (Throwable $e): void {
  error_log(sprintf(
    '[%s] Uncaught exception: %s in %s:%d',
    date('c'),
    $e->getMessage(),
    $e->getFile(),
    $e->getLine()
  ));
  abort(500, 'Ocurrió un error interno. Intentá nuevamente más tarde.');
});

register_shutdown_function(function (): void {
  $error = error_get_last();
  if ($error !== null) {
    error_log(sprintf(
      '[%s] Fatal error: %s in %s:%d',
      date('c'),
      $error['message'] ?? 'unknown',
      $error['file'] ?? 'unknown',
      $error['line'] ?? 0
    ));
    if (!headers_sent()) {
      abort(500, 'Ocurrió un error interno. Intentá nuevamente más tarde.');
    }
  }
});

$gatewayCookieDays = (int)($auth['gateway_cookie_lifetime_days'] ?? 0);
if ($gatewayCookieDays <= 0) {
  $gatewayCookieDays = 3650;
}
$sessionDays = (int)($auth['session_lifetime_days'] ?? 30);
if ($sessionDays <= 0) {
  $sessionDays = 30;
}
$sessionDays = max($sessionDays, $gatewayCookieDays);
$sessionGcLifetime = $sessionDays * 86400;
ini_set('session.gc_maxlifetime', (string)$sessionGcLifetime);
ini_set('session.cookie_lifetime', '0');
ini_set('session.use_strict_mode', '1');
$cookiePath = BASE_PATH !== '' ? BASE_PATH : '/';
session_set_cookie_params([
  'lifetime' => 0,
  'path' => $cookiePath,
  'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
  error_log(sprintf('[%s] Session failed to start.', date('c')));
  abort(500, 'No se pudo iniciar la sesión. Intentá nuevamente más tarde.');
}

function abort(int $code, string $message): void {
  http_response_code($code);
  echo "<h1>Ocurrió un problema</h1>";
  echo "<p>" . htmlspecialchars($message) . "</p>";
  exit;
}

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function asset_url(string $path): string {
  return asset_path($path);
}

function cash_asset_url(string $path): string {
  return asset_path($path);
}

function url_path(string $path): string {
  return rtrim(APP_BASE, '/') . '/' . ltrim($path, '/');
}

function asset_path(string $path): string {
  return url_path('assets/' . ltrim($path, '/'));
}

function redirect(string $to): void {
  if (headers_sent($file, $line)) {
    error_log(sprintf('[%s] Redirect blocked because headers were already sent at %s:%d', date('c'), $file, $line));
    abort(500, 'No se pudo completar la redirección.');
  }
  header("Location: {$to}");
  exit;
}

function auth_config(): array {
  global $auth;
  return $auth ?? [];
}

require_once __DIR__ . '/auth.php';

function require_role(array $roles, string $message = 'Sin permisos'): void {
  require_login();
  $user = current_user();
  $role = $user['role'] ?? '';
  if (!in_array($role, $roles, true)) {
    abort(403, $message);
  }
}

function require_permission(bool $allowed, string $message = 'Sin permisos'): void {
  if (!$allowed) {
    abort(403, $message);
  }
}

function current_user(): array {
  if (empty($_SESSION['logged_in']) || empty($_SESSION['user'])) {
    return [];
  }
  return $_SESSION['user'];
}

function current_role(): string {
  $user = current_user();
  return (string)($user['role'] ?? '');
}

function getRoleKeyFromSession(): string {
  return current_role();
}

function role_default_definitions(): array {
  return [
    'superadmin' => ['name' => 'Superadmin', 'is_system' => true],
  ];
}

function permission_default_definitions(): array {
  return [
    'roles_access' => [
      'superadmin' => true,
      'admin' => false,
      'vendedor' => false,
      'lectura' => false,
    ],
    'menu_config_prestashop' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'menu_import_csv' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'menu_design' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => true,
    ],
    'menu_new_list' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'menu_new_product' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'tasks_settings' => [
      'superadmin' => true,
      'admin' => false,
      'vendedor' => false,
      'lectura' => false,
    ],
    'tasks_delete' => [
      'superadmin' => true,
      'admin' => false,
      'vendedor' => false,
      'lectura' => false,
    ],
    'can_delete_messages' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'list_can_sync' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'list_can_delete_item' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'list_can_close' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'list_can_open' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'list_can_scan' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'product_can_edit' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'product_edit_data' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'product_edit_providers' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'product_edit_stock' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'product_edit_ml' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'product_stock_pull_prestashop' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'product_can_add_code' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => true,
      'lectura' => false,
    ],
    'stock_set' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'cash.view_entries_detail' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'cash.view_exits_detail' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'cash.entries.edit' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'cash.entries.delete' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'cash.exits.edit' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
    'cash.exits.delete' => [
      'superadmin' => true,
      'admin' => true,
      'vendedor' => false,
      'lectura' => false,
    ],
  ];
}

function ensure_roles_schema(): void {
  static $done = false;
  if ($done) {
    return;
  }
  require_once __DIR__ . '/db.php';
  $pdo = db();
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS roles (
      role_key VARCHAR(32) NOT NULL,
      role_name VARCHAR(64) NOT NULL,
      is_system TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (role_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS role_permissions (
      role_key VARCHAR(32) NOT NULL,
      perm_key VARCHAR(64) NOT NULL,
      perm_value TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (role_key, perm_key),
      CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_key) REFERENCES roles(role_key)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS role_cashbox_permissions (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      role_key VARCHAR(32) NOT NULL,
      cashbox_id INT UNSIGNED NOT NULL,
      can_view TINYINT(1) NOT NULL DEFAULT 0,
      can_open_module TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_cashboxes TINYINT(1) NOT NULL DEFAULT 0,
      can_view_balance TINYINT(1) NOT NULL DEFAULT 0,
      can_create_entries TINYINT(1) NOT NULL DEFAULT 0,
      can_create_exits TINYINT(1) NOT NULL DEFAULT 0,
      can_configure_bills TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_role_cashbox (role_key, cashbox_id),
      KEY idx_role_cashbox_role (role_key),
      KEY idx_role_cashbox_cashbox (cashbox_id),
      CONSTRAINT fk_role_cashbox_role
        FOREIGN KEY (role_key) REFERENCES roles(role_key)
        ON DELETE CASCADE,
      CONSTRAINT fk_role_cashbox_cashbox
        FOREIGN KEY (cashbox_id) REFERENCES cashboxes(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $done = true;
}

function ensure_roles_defaults(): void {
  static $seeded = false;
  if ($seeded) {
    return;
  }
  ensure_roles_schema();
  ensure_superadmin_role_exists();
  reset_superadmin_permissions();
  $seeded = true;
}

function ensure_superadmin_role_exists(): void {
  ensure_roles_schema();
  require_once __DIR__ . '/db.php';
  $pdo = db();
  $st = $pdo->prepare("SELECT 1 FROM roles WHERE role_key = 'superadmin' LIMIT 1");
  $st->execute();
  if ($st->fetchColumn()) {
    return;
  }
  $role = role_default_definitions()['superadmin'];
  $insert = $pdo->prepare("INSERT INTO roles(role_key, role_name, is_system) VALUES(?, ?, ?)");
  $insert->execute(['superadmin', $role['name'], $role['is_system'] ? 1 : 0]);
  $permission_defaults = permission_default_definitions();
  $perm_st = $pdo->prepare("INSERT INTO role_permissions(role_key, perm_key, perm_value) VALUES(?, ?, ?)");
  foreach ($permission_defaults as $perm_key => $_values) {
    $perm_st->execute(['superadmin', $perm_key, 1]);
  }
}

function ensure_superadmin_full_access(): void {
  reset_superadmin_permissions();
}

function reset_superadmin_permissions(): void {
  ensure_roles_schema();
  require_once __DIR__ . '/db.php';
  $pdo = db();
  $exists = $pdo->prepare("SELECT 1 FROM roles WHERE role_key = 'superadmin' LIMIT 1");
  $exists->execute();
  if (!$exists->fetchColumn()) {
    return;
  }

  $permission_defaults = permission_default_definitions();
  $perm_keys = array_keys($permission_defaults);
  if (empty($perm_keys)) {
    return;
  }

  $placeholders = implode(',', array_fill(0, count($perm_keys), '?'));
  $check = $pdo->prepare(
    "SELECT perm_key
     FROM role_permissions
     WHERE role_key = 'superadmin'
       AND perm_key IN ({$placeholders})
       AND perm_value = 1"
  );
  $check->execute($perm_keys);
  $restored_keys = array_fill_keys($perm_keys, true);
  while ($row = $check->fetch()) {
    unset($restored_keys[(string)($row['perm_key'] ?? '')]);
  }
  if (empty($restored_keys)) {
    return;
  }

  $st = $pdo->prepare("INSERT INTO role_permissions(role_key, perm_key, perm_value) VALUES('superadmin', ?, 1)
    ON DUPLICATE KEY UPDATE perm_value = 1");
  foreach (array_keys($restored_keys) as $perm_key) {
    $st->execute([$perm_key]);
  }

  error_log('Superadmin permissions restored');
}

function ensure_superadmin_permissions_for_session(): void {
  static $done = false;
  if ($done) {
    return;
  }
  if (current_role() !== 'superadmin') {
    return;
  }
  reset_superadmin_permissions();
  $done = true;
}

function hasPerm(string $perm_key): bool {
  $role_key = getRoleKeyFromSession();
  if ($role_key === 'superadmin') {
    return true;
  }
  ensure_roles_defaults();

  static $cache = [];
  if (isset($cache[$role_key]) && array_key_exists($perm_key, $cache[$role_key])) {
    return $cache[$role_key][$perm_key];
  }
  require_once __DIR__ . '/db.php';
  $pdo = db();
  $st = $pdo->prepare("SELECT perm_value FROM role_permissions WHERE role_key = ? AND perm_key = ? LIMIT 1");
  $st->execute([$role_key, $perm_key]);
  $row = $st->fetch();
  if ($row) {
    $value = (bool)$row['perm_value'];
  } else {
    $defaults = permission_default_definitions();
    $value = !empty($defaults[$perm_key][$role_key]);
  }
  $cache[$role_key][$perm_key] = $value;
  return $value;
}

function cashbox_permission_keys(): array {
  return [
    'can_view',
    'can_open_module',
    'can_manage_cashboxes',
    'can_view_balance',
    'can_create_entries',
    'can_create_exits',
    'can_configure_bills',
  ];
}

function get_cashbox_permissions_for_role(string $role_key, int $cashbox_id): array {
  static $cache = [];
  if ($cashbox_id <= 0) {
    return array_fill_keys(cashbox_permission_keys(), false);
  }
  if (isset($cache[$role_key][$cashbox_id])) {
    return $cache[$role_key][$cashbox_id];
  }
  ensure_roles_schema();
  require_once __DIR__ . '/db.php';
  $pdo = db();
  $st = $pdo->prepare(
    "SELECT can_view, can_open_module, can_manage_cashboxes, can_view_balance,
      can_create_entries, can_create_exits, can_configure_bills
     FROM role_cashbox_permissions
     WHERE role_key = ? AND cashbox_id = ? LIMIT 1"
  );
  $st->execute([$role_key, $cashbox_id]);
  $row = $st->fetch() ?: [];
  $permissions = [
    'can_view' => !empty($row['can_view']),
    'can_open_module' => !empty($row['can_open_module']),
    'can_manage_cashboxes' => !empty($row['can_manage_cashboxes']),
    'can_view_balance' => !empty($row['can_view_balance']),
    'can_create_entries' => !empty($row['can_create_entries']),
    'can_create_exits' => !empty($row['can_create_exits']),
    'can_configure_bills' => !empty($row['can_configure_bills']),
  ];
  $cache[$role_key][$cashbox_id] = $permissions;
  return $permissions;
}

function hasCashboxPerm(string $perm_key, int $cashbox_id): bool {
  $role_key = getRoleKeyFromSession();
  if ($role_key === 'superadmin') {
    return true;
  }
  if (!in_array($perm_key, cashbox_permission_keys(), true)) {
    return false;
  }
  $permissions = get_cashbox_permissions_for_role($role_key, $cashbox_id);
  return !empty($permissions[$perm_key]);
}

function hasAnyCashboxPerm(string $perm_key): bool {
  $role_key = getRoleKeyFromSession();
  if ($role_key === 'superadmin') {
    return true;
  }
  if (!in_array($perm_key, cashbox_permission_keys(), true)) {
    return false;
  }
  ensure_roles_schema();
  require_once __DIR__ . '/db.php';
  $pdo = db();
  $st = $pdo->prepare("SELECT 1 FROM role_cashbox_permissions WHERE role_key = ? AND {$perm_key} = 1 LIMIT 1");
  $st->execute([$role_key]);
  return (bool)$st->fetchColumn();
}

function can_import_csv(): bool {
  return hasPerm('menu_import_csv');
}

function can_sync_prestashop(): bool {
  return hasPerm('list_can_sync');
}

function can_delete_list_item(): bool {
  return hasPerm('list_can_delete_item');
}

function is_readonly_role(): bool {
  return !hasPerm('menu_new_list')
    && !hasPerm('menu_new_product')
    && !hasPerm('list_can_scan')
    && !hasPerm('product_can_edit')
    && !hasPerm('product_can_add_code');
}

function can_create_list(): bool {
  return hasPerm('menu_new_list');
}

function can_create_product(): bool {
  return hasPerm('menu_new_product');
}

function can_edit_list(): bool {
  return hasPerm('product_can_edit');
}

function can_scan(): bool {
  return hasPerm('list_can_scan');
}

function can_close_list(): bool {
  return hasPerm('list_can_close');
}

function can_reopen_list(): bool {
  return hasPerm('list_can_open');
}

function can_delete_task(): bool {
  return hasPerm('tasks_delete');
}

function can_edit_product(): bool {
  return hasPerm('product_can_edit');
}

function can_delete_messages(): bool {
  return hasPerm('can_delete_messages');
}

function can_add_code(): bool {
  return hasPerm('product_can_add_code');
}

function can_set_stock(): bool {
  return hasPerm('stock_set');
}

function is_post(): bool {
  return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post(string $key, string $default=''): string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get(string $key, string $default=''): string {
  return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(string $token): bool {
  $session_token = (string)($_SESSION['csrf_token'] ?? '');
  return $session_token !== '' && hash_equals($session_token, $token);
}

function theme_catalog(): array {
  return [
    'theme_default' => [
      'name' => 'Default pastel',
      'description' => 'Minimalista, claro y suave en colores pastel.',
    ],
    'theme_dark' => [
      'name' => 'Dark pastel',
      'description' => 'La misma estética suave, en versión oscura.',
    ],
    'theme_trek' => [
      'name' => 'Trek futurista',
      'description' => 'Inspiración sci-fi con paneles luminosos y glow sutil.',
    ],
    'theme_colorful' => [
      'name' => 'Color splash',
      'description' => 'Explosión vibrante de color con gradientes intensos y energía juvenil.',
    ],
    'theme_redgold' => [
      'name' => 'Rojo & Oro',
      'description' => 'Estética elegante en rojo profundo con acentos dorados suaves.',
    ],
    'theme_ares' => [
      'name' => 'Ares',
      'description' => 'Oscuro y futurista, con neón rojo y acentos dorados.',
    ],
  ];
}

function current_theme(): string {
  $theme = (string)($_SESSION['user']['theme'] ?? 'theme_default');
  $themes = theme_catalog();
  if (!isset($themes[$theme])) {
    $theme = 'theme_default';
  }
  return $theme;
}

function theme_css_links(): string {
  $theme = current_theme();
  $base = '<link rel="stylesheet" href="' . asset_url('themes/base.css') . '">';
  $theme_css = '<link rel="stylesheet" href="' . asset_url('themes/' . $theme . '.css') . '" id="theme-stylesheet">';
  return $base . $theme_css;
}

ensure_superadmin_permissions_for_session();
