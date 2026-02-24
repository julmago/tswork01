<?php
declare(strict_types=1);

function has_gateway_session(): bool {
  if (!empty($_SESSION['gateway_logged'])) {
    return true;
  }
  if (!empty($_SESSION['gateway_ok'])) {
    $_SESSION['gateway_logged'] = true;
    unset($_SESSION['gateway_ok']);
    return true;
  }
  return false;
}

function refresh_gateway_session_cookie(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    return;
  }
  if (!has_gateway_session()) {
    return;
  }
  if (!ini_get('session.use_cookies')) {
    return;
  }
  $params = session_get_cookie_params();
  $options = [
    'path' => $params['path'] ?? '/',
    'domain' => $params['domain'] ?? '',
    'secure' => $params['secure'] ?? false,
    'httponly' => $params['httponly'] ?? true,
    'samesite' => $params['samesite'] ?? 'Lax',
  ];
  if (headers_sent($file, $line)) {
    error_log(sprintf('[%s] Session cookie refresh skipped because headers were already sent at %s:%d', date('c'), $file, $line));
    return;
  }
  setcookie(session_name(), session_id(), $options);
}

function clear_profile_session(): void {
  unset(
    $_SESSION['profile_user_id'],
    $_SESSION['profile_logged'],
    $_SESSION['profile_last_activity'],
    $_SESSION['user'],
    $_SESSION['logged_in']
  );
}

function clear_gateway_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    return;
  }
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
      ]
    );
  }
  session_destroy();
}

function is_profile_session_expired(): bool {
  $last = (int)($_SESSION['profile_last_activity'] ?? 0);
  if ($last <= 0) {
    return false;
  }
  $timeout = 8 * 60 * 60;
  return (time() - $last) > $timeout;
}

function require_gateway(): void {
  if (!has_gateway_session()) {
    redirect('login.php');
  }
  refresh_gateway_session_cookie();
}

function require_login(): void {
  if (!has_gateway_session()) {
    redirect('login.php');
  }
  if (empty($_SESSION['profile_logged']) || empty($_SESSION['profile_user_id'])) {
    redirect('select_profile.php');
  }
  if (is_profile_session_expired()) {
    clear_profile_session();
    redirect('select_profile.php?expired=1');
  }
  $_SESSION['profile_last_activity'] = time();
  refresh_gateway_session_cookie();
}
