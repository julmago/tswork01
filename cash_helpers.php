<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

function cashbox_selected_id(): int {
  return (int)($_SESSION['cashbox_id'] ?? 0);
}

function fetch_cashbox_by_id(int $cashbox_id, bool $only_active = true): ?array {
  if ($cashbox_id <= 0) {
    return null;
  }
  $sql = "SELECT id, name, is_active, created_by_user_id, created_at FROM cashboxes WHERE id = ?";
  if ($only_active) {
    $sql .= " AND is_active = 1";
  }
  $sql .= " LIMIT 1";
  $st = db()->prepare($sql);
  $st->execute([$cashbox_id]);
  $row = $st->fetch();
  return $row ?: null;
}

function fetch_active_cashboxes(): array {
  $st = db()->query("SELECT id, name FROM cashboxes WHERE is_active = 1 ORDER BY name ASC");
  return $st->fetchAll();
}

function getAllowedCashboxes(PDO $pdo, array $currentUser): array {
  $role_key = (string)($currentUser['role'] ?? '');
  if ($role_key === '') {
    return [];
  }
  ensure_roles_schema();
  $st = $pdo->prepare(
    "SELECT cb.id, cb.name
     FROM cashboxes cb
     JOIN role_cashbox_permissions rcp
       ON rcp.cashbox_id = cb.id
     WHERE rcp.role_key = ? AND rcp.can_view = 1
     ORDER BY cb.name ASC"
  );
  $st->execute([$role_key]);
  return $st->fetchAll();
}

function fetch_accessible_cashboxes(string $perm_key): array {
  if (!in_array($perm_key, cashbox_permission_keys(), true)) {
    return [];
  }
  $sql = "SELECT c.id, c.name
    FROM cashboxes c
    JOIN role_cashbox_permissions rcp
      ON rcp.cashbox_id = c.id AND rcp.role_key = ?
    WHERE c.is_active = 1 AND rcp.{$perm_key} = 1
    ORDER BY c.name ASC";
  $st = db()->prepare($sql);
  $st->execute([getRoleKeyFromSession()]);
  return $st->fetchAll();
}

function fetch_cashboxes(): array {
  $st = db()->query("SELECT id, name, is_active FROM cashboxes ORDER BY name ASC");
  return $st->fetchAll();
}

function cashbox_is_active($value): bool {
  if (is_string($value)) {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return false;
    }
    if (ctype_digit($trimmed)) {
      return (int)$trimmed > 0;
    }
    if (strlen($value) === 1) {
      return ord($value) > 0;
    }
    $normalized = mb_strtolower($trimmed);
    return in_array($normalized, ['true', 't', 'yes', 'y', 'si', 'sí', 's', 'activo', 'activa'], true);
  }
  return (int)$value > 0;
}

function require_cashbox_selected(bool $only_active = true): array {
  $cashbox_id = cashbox_selected_id();
  if ($cashbox_id <= 0) {
    redirect(url_path('cash_select.php'));
  }
  $cashbox = fetch_cashbox_by_id($cashbox_id, $only_active);
  if (!$cashbox) {
    unset($_SESSION['cashbox_id']);
    redirect(url_path('cash_select.php'));
  }
  require_permission(hasCashboxPerm('can_view', $cashbox_id), 'Sin permiso para acceder a esta caja.');
  return $cashbox;
}
