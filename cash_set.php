<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
$u = current_user();

$cashbox_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cashbox = fetch_cashbox_by_id($cashbox_id, false);
$redirect = urldecode((string)($_GET['redirect'] ?? ''));

if ($redirect === '') {
  $redirect = url_path('cash_select.php');
}

if (strpos($redirect, '://') !== false || str_starts_with($redirect, '//')) {
  $redirect = url_path('cash_select.php');
}

if (!$cashbox) {
  redirect(url_path('cash_select.php?error=invalid'));
}

require_permission(hasCashboxPerm('can_open_module', $cashbox_id), 'Sin permiso para acceder a esta caja.');

$previous_cashbox_id = $_SESSION['cashbox_id'] ?? null;
$_SESSION['cashbox_id'] = $cashbox_id;
error_log(sprintf(
  'cash_set: user_id=%s cashbox_id=%d previous_cashbox_id=%s redirect=%s',
  (string)($u['id'] ?? 'n/a'),
  $cashbox_id,
  $previous_cashbox_id === null ? 'null' : (string)$previous_cashbox_id,
  $redirect
));
redirect($redirect);
