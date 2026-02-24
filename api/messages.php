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
  if (count($segments) >= 3 && $segments[0] === 'api' && $segments[1] === 'messages') {
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

function allowed_entity_types(): array {
  return ['product', 'listado', 'pedido', 'proveedor', 'user'];
}

function allowed_statuses(): array {
  return ['abierto', 'en_proceso', 'resuelto', 'archivado'];
}

function allowed_message_types(): array {
  return ['observacion', 'problema', 'consulta', 'accion'];
}

function resolve_assigned_user_id(PDO $pdo, int $requested_user_id): int {
  if ($requested_user_id <= 0) {
    return 0;
  }
  $st = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
  $st->execute([$requested_user_id]);
  $row = $st->fetch();
  return $row ? (int)$row['id'] : 0;
}

function is_superadmin_role(): bool {
  return current_role() === 'superadmin';
}


function can_delete_messages_role(): bool {
  if (is_superadmin_role()) {
    return true;
  }
  return can_delete_messages();
}

function parse_mentions(PDO $pdo, string $body, int $author_id): array {
  if (!preg_match_all('/@([\p{L}\p{N}._-]+)/u', $body, $matches)) {
    return [];
  }
  $tokens = array_unique(array_map(static fn($value) => mb_strtolower((string)$value), $matches[1]));
  $user_ids = [];
  $userLookup = $pdo->prepare(
    "SELECT id FROM users WHERE is_active = 1 AND (LOWER(email) = ? OR LOWER(first_name) = ? OR LOWER(SUBSTRING_INDEX(email, '@', 1)) = ?) LIMIT 1"
  );
  $userById = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND id = ? LIMIT 1");
  foreach ($tokens as $token) {
    if ($token === '') {
      continue;
    }
    $user_id = null;
    if (ctype_digit($token)) {
      $userById->execute([(int)$token]);
      $row = $userById->fetch();
      $user_id = $row ? (int)$row['id'] : null;
    } else {
      $userLookup->execute([$token, $token, $token]);
      $row = $userLookup->fetch();
      $user_id = $row ? (int)$row['id'] : null;
    }
    if ($user_id && $user_id !== $author_id) {
      $user_ids[] = $user_id;
    }
  }
  return array_values(array_unique($user_ids));
}

function resolve_recipient_ids(PDO $pdo, int $current_user_id): array {
  $ids = [];
  $single = (int)post('assigned_to_user_id', '0');
  if ($single > 0) {
    $ids[] = $single;
  }
  $bulk = $_POST['assigned_to_user_ids'] ?? ($_POST['assigned_to_user_ids[]'] ?? null);
  if (is_array($bulk)) {
    foreach ($bulk as $bulkId) {
      $ids[] = (int)$bulkId;
    }
  } elseif ($bulk !== null && $bulk !== '') {
    $ids[] = (int)$bulk;
  }
  if (post('send_to_all') === '1') {
    $st = $pdo->query('SELECT id FROM users WHERE is_active = 1');
    $rows = $st ? $st->fetchAll() : [];
    foreach ($rows as $row) {
      $ids[] = (int)$row['id'];
    }
  }
  $ids = array_values(array_unique(array_filter($ids, static fn($value) => $value > 0)));
  if (!$ids) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND id IN ({$placeholders})");
  $st->execute($ids);
  $validRows = $st->fetchAll();
  $valid = [];
  foreach ($validRows as $row) {
    $valid[] = (int)$row['id'];
  }
  return array_values(array_unique($valid));
}

$action = api_action();
$pdo = db();
$current = current_user();
$current_user_id = (int)($current['id'] ?? 0);

if ($action === '' && !is_post()) {
  $action = 'list';
}

if ($action === 'create') {
  if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Método inválido.'], 405);
  }
  require_csrf();
  $entity_type = post('entity_type');
  $entity_id = (int)post('entity_id', '0');
  $title = trim((string)post('title'));
  $body = trim((string)post('body'));
  if ($body === '') {
    $body = trim((string)post('message'));
  }
  $message_type = post('message_type');
  $status = post('status');
  $assigned_to_user_id = (int)post('assigned_to_user_id', '0');
  $parent_id = (int)post('parent_id', '0');
  $thread_id = (int)post('thread_id', '0');

  if (!in_array($entity_type, allowed_entity_types(), true)) {
    json_response(['ok' => false, 'error' => 'Entidad inválida.'], 400);
  }
  if ($entity_id <= 0) {
    json_response(['ok' => false, 'error' => 'ID inválido.'], 400);
  }
  if ($body === '' || mb_strlen($body) > 5000) {
    json_response(['ok' => false, 'error' => 'El mensaje es obligatorio y debe tener menos de 5000 caracteres.'], 400);
  }

  $is_instant_message = post('require_assignee') === '1' || $entity_type === 'user';
  if ($title === '' && !$is_instant_message) {
    $title = mb_substr($body, 0, 160);
  }
  if ($title === '' || mb_strlen($title) > 160) {
    json_response(['ok' => false, 'error' => 'El título es obligatorio y debe tener hasta 160 caracteres.'], 400);
  }

  if (!in_array($message_type, allowed_message_types(), true)) {
    $message_type = 'observacion';
  }
  if (!in_array($status, allowed_statuses(), true)) {
    $status = 'abierto';
  }

  $recipients = resolve_recipient_ids($pdo, $current_user_id);
  if ($assigned_to_user_id > 0 && !in_array($assigned_to_user_id, $recipients, true)) {
    $resolved_single = resolve_assigned_user_id($pdo, $assigned_to_user_id);
    if ($resolved_single <= 0) {
      json_response(['ok' => false, 'error' => 'El usuario asignado es inválido o está inactivo.'], 400);
    }
    $recipients[] = $resolved_single;
  }
  $recipients = array_values(array_unique($recipients));

  $require_assignee = post('require_assignee') === '1' || $entity_type === 'user';
  if ($require_assignee && !$recipients) {
    json_response(['ok' => false, 'error' => 'Debés seleccionar al menos un destinatario para este mensaje.'], 400);
  }

  $assigned_user_id = $recipients ? (int)$recipients[0] : 0;
  if ($entity_type === 'user' && $assigned_user_id > 0) {
    $entity_id = $assigned_user_id;
  }

  if ($parent_id > 0) {
    $stParent = $pdo->prepare('SELECT id, thread_id FROM ts_messages WHERE id = ? LIMIT 1');
    $stParent->execute([$parent_id]);
    $parent = $stParent->fetch();
    if (!$parent) {
      json_response(['ok' => false, 'error' => 'Mensaje padre inválido.'], 400);
    }
    if ($thread_id <= 0) {
      $thread_id = (int)($parent['thread_id'] ?? 0);
      if ($thread_id <= 0) {
        $thread_id = (int)$parent['id'];
      }
    }
  }

  try {
    $pdo->beginTransaction();

    $st = $pdo->prepare(
      "INSERT INTO ts_messages (entity_type, entity_id, title, thread_id, parent_id, message_type, status, body, created_by, assigned_to_user_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $st->execute([
      $entity_type,
      $entity_id,
      $title,
      $thread_id > 0 ? $thread_id : null,
      $parent_id > 0 ? $parent_id : null,
      $message_type,
      $status,
      $body,
      $current_user_id,
      $assigned_user_id > 0 ? $assigned_user_id : null,
    ]);
    $message_id = (int)$pdo->lastInsertId();

    if ($thread_id <= 0) {
      $thread_id = $message_id;
      $stThread = $pdo->prepare('UPDATE ts_messages SET thread_id = ? WHERE id = ?');
      $stThread->execute([$thread_id, $message_id]);
    }

    if ($recipients) {
      $recipientInsert = $pdo->prepare('INSERT IGNORE INTO ts_message_recipients (message_id, user_id) VALUES (?, ?)');
      $assignedNotif = $pdo->prepare("INSERT INTO ts_notifications (user_id, type, message_id) VALUES (?, 'assigned', ?)");
      foreach ($recipients as $recipient_id) {
        $recipientInsert->execute([$message_id, $recipient_id]);
        $assignedNotif->execute([$recipient_id, $message_id]);
      }
    }

    $mentions = parse_mentions($pdo, $body, $current_user_id);
    if ($mentions) {
      $mentionInsert = $pdo->prepare(
        'INSERT IGNORE INTO ts_message_mentions (message_id, mentioned_user_id) VALUES (?, ?)'
      );
      $notifInsert = $pdo->prepare(
        "INSERT INTO ts_notifications (user_id, type, message_id) VALUES (?, 'mention', ?)"
      );
      foreach ($mentions as $mentioned_user_id) {
        $mentionInsert->execute([$message_id, $mentioned_user_id]);
        $notifInsert->execute([$mentioned_user_id, $message_id]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => 'Error interno DB.'], 500);
  }

  json_response(['ok' => true, 'message_id' => $message_id, 'thread_id' => $thread_id]);
}

if ($action === 'list') {
  $entity_type = get('entity_type');
  $entity_id = (int)get('entity_id', '0');
  $status = get('status');
  $mine = get('mine');
  $mentioned = get('mentioned');

  if (!in_array($entity_type, allowed_entity_types(), true)) {
    json_response(['ok' => false, 'error' => 'Entidad inválida.'], 400);
  }
  if ($entity_id <= 0) {
    json_response(['ok' => false, 'error' => 'ID inválido.'], 400);
  }

  $params = [$entity_type, $entity_id];
  $joins = '';
  $conditions = ['m.entity_type = ?', 'm.entity_id = ?'];

  if ($status !== '') {
    if ($status === 'open') {
      $conditions[] = "m.status IN ('abierto','en_proceso')";
    } elseif (in_array($status, allowed_statuses(), true)) {
      $conditions[] = 'm.status = ?';
      $params[] = $status;
    }
  } else {
    $conditions[] = "m.status <> 'archivado'";
  }

  if ($mine === '1') {
    $conditions[] = 'm.created_by = ?';
    $params[] = $current_user_id;
  }

  if ($mentioned === '1') {
    $joins .= ' JOIN ts_message_mentions mm ON mm.message_id = m.id AND mm.mentioned_user_id = ?';
    $params[] = $current_user_id;
  }

  $sql = "
    SELECT m.id, m.entity_type, m.entity_id, m.title, m.thread_id, m.parent_id, m.message_type, m.status, m.body,
           m.created_at, m.created_by, m.assigned_to_user_id,
           u.first_name, u.last_name, u.email,
           au.first_name AS assigned_first_name, au.last_name AS assigned_last_name, au.email AS assigned_email
    FROM ts_messages m
    JOIN users u ON u.id = m.created_by
    LEFT JOIN users au ON au.id = m.assigned_to_user_id
    {$joins}
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY m.created_at DESC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  $items = [];
  foreach ($rows as $row) {
    $author_name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    if ($author_name === '') {
      $author_name = (string)$row['email'];
    }
    $assigned_name = '';
    if ((int)($row['assigned_to_user_id'] ?? 0) > 0) {
      $assigned_name = trim((string)($row['assigned_first_name'] ?? '') . ' ' . (string)($row['assigned_last_name'] ?? ''));
      if ($assigned_name === '') {
        $assigned_name = (string)($row['assigned_email'] ?? '');
      }
    }
    $items[] = [
      'id' => (int)$row['id'],
      'entity_type' => (string)$row['entity_type'],
      'entity_id' => (int)$row['entity_id'],
      'title' => (string)$row['title'],
      'thread_id' => (int)($row['thread_id'] ?? 0),
      'parent_id' => (int)($row['parent_id'] ?? 0),
      'message_type' => (string)$row['message_type'],
      'status' => (string)$row['status'],
      'body' => (string)$row['body'],
      'created_at' => (string)$row['created_at'],
      'created_by' => (int)$row['created_by'],
      'author_name' => $author_name,
      'assigned_to_user_id' => (int)($row['assigned_to_user_id'] ?? 0),
      'assigned_to_name' => $assigned_name,
      'can_edit' => ((int)$row['created_by'] === $current_user_id) || is_superadmin_role(),
    ];
  }
  json_response(['ok' => true, 'items' => $items]);
}

if ($action === 'status') {
  if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Método inválido.'], 405);
  }
  require_csrf();
  $message_id = (int)post('message_id', '0');
  $status = post('status');
  if ($message_id <= 0 || !in_array($status, allowed_statuses(), true)) {
    json_response(['ok' => false, 'error' => 'Parámetros inválidos.'], 400);
  }
  $st = $pdo->prepare('SELECT id, created_by FROM ts_messages WHERE id = ?');
  $st->execute([$message_id]);
  $message = $st->fetch();
  if (!$message) {
    json_response(['ok' => false, 'error' => 'Mensaje no encontrado.'], 404);
  }
  $can_edit = ((int)$message['created_by'] === $current_user_id) || is_superadmin_role();
  if (!$can_edit) {
    json_response(['ok' => false, 'error' => 'Sin permisos.'], 403);
  }
  $archived_at = $status === 'archivado' ? 'NOW()' : 'NULL';
  $sql = "UPDATE ts_messages SET status = ?, updated_at = NOW(), archived_at = {$archived_at} WHERE id = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$status, $message_id]);
  json_response(['ok' => true]);
}


if ($action === 'delete') {
  if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Método inválido.'], 405);
  }
  require_csrf();
  if (!can_delete_messages_role()) {
    json_response(['ok' => false, 'error' => 'No autorizado'], 403);
  }

  $message_id = (int)post('message_id', '0');
  if ($message_id <= 0) {
    json_response(['ok' => false, 'error' => 'Parámetros inválidos.'], 400);
  }

  $st = $pdo->prepare('SELECT id, thread_id, parent_id FROM ts_messages WHERE id = ? LIMIT 1');
  $st->execute([$message_id]);
  $message = $st->fetch();
  if (!$message) {
    json_response(['ok' => false, 'error' => 'Mensaje no encontrado.'], 404);
  }

  $thread_id = (int)($message['thread_id'] ?? 0);
  if ($thread_id <= 0) {
    $thread_id = (int)$message['id'];
  }
  $is_root_message = (int)($message['parent_id'] ?? 0) <= 0;

  try {
    $pdo->beginTransaction();

    $target_message_ids = [];
    if ($is_root_message) {
      $stThread = $pdo->prepare('SELECT id FROM ts_messages WHERE thread_id = ?');
      $stThread->execute([$thread_id]);
      $rows = $stThread->fetchAll();
      foreach ($rows as $row) {
        $target_message_ids[] = (int)$row['id'];
      }
    } else {
      $target_message_ids[] = (int)$message['id'];
    }

    $target_message_ids = array_values(array_unique(array_filter($target_message_ids, static fn($id) => $id > 0)));
    if (!$target_message_ids) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'No se encontraron mensajes para eliminar.'], 404);
    }

    $placeholders = implode(',', array_fill(0, count($target_message_ids), '?'));

    $deleteMentions = $pdo->prepare("DELETE FROM ts_message_mentions WHERE message_id IN ({$placeholders})");
    $deleteMentions->execute($target_message_ids);

    $deleteRecipients = $pdo->prepare("DELETE FROM ts_message_recipients WHERE message_id IN ({$placeholders})");
    $deleteRecipients->execute($target_message_ids);

    $deleteNotifications = $pdo->prepare("DELETE FROM ts_notifications WHERE message_id IN ({$placeholders})");
    $deleteNotifications->execute($target_message_ids);

    $deleteMessages = $pdo->prepare("DELETE FROM ts_messages WHERE id IN ({$placeholders})");
    $deleteMessages->execute($target_message_ids);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => 'Error interno DB.'], 500);
  }

  json_response(['ok' => true, 'deleted_message_ids' => $target_message_ids]);
}

if ($action === 'archive') {
  if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Método inválido.'], 405);
  }
  require_csrf();
  $message_id = (int)post('message_id', '0');
  if ($message_id <= 0) {
    json_response(['ok' => false, 'error' => 'Parámetros inválidos.'], 400);
  }
  $st = $pdo->prepare('SELECT id, created_by FROM ts_messages WHERE id = ?');
  $st->execute([$message_id]);
  $message = $st->fetch();
  if (!$message) {
    json_response(['ok' => false, 'error' => 'Mensaje no encontrado.'], 404);
  }
  $can_edit = ((int)$message['created_by'] === $current_user_id) || is_superadmin_role();
  if (!$can_edit) {
    json_response(['ok' => false, 'error' => 'Sin permisos.'], 403);
  }
  $st = $pdo->prepare("UPDATE ts_messages SET status = 'archivado', archived_at = NOW(), updated_at = NOW() WHERE id = ?");
  $st->execute([$message_id]);
  json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Acción inválida.'], 400);
