<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$view = get('view', 'inbox');
if (!in_array($view, ['inbox', 'sent', 'new'], true)) {
  $view = 'inbox';
}

$filter = get('filter', 'unread');
if (!in_array($filter, ['unread', 'all'], true)) {
  $filter = 'unread';
}

$pdo = db();
$user = current_user();
$user_id = (int)($user['id'] ?? 0);

$users_st = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY first_name, last_name, email");
$users = $users_st ? $users_st->fetchAll() : [];

$inbox_items = [];
$sent_items = [];
$thread_ids = [];

$show_new_message = $view === 'new';
$show_notifications = !$show_new_message;

if ($view === 'inbox') {
  $where = 'n.user_id = ?';
  $params = [$user_id];
  if ($filter === 'unread') {
    $where .= ' AND n.is_read = 0';
  }

  $st = $pdo->prepare(
    "SELECT n.id AS notification_id, n.type, n.is_read, n.created_at AS notification_created_at,
            m.id AS message_id, m.entity_type, m.entity_id, m.title, m.thread_id, m.parent_id, m.body, m.status, m.message_type,
            m.created_at, m.created_by,
            u.first_name, u.last_name, u.email
     FROM ts_notifications n
     LEFT JOIN ts_messages m ON m.id = n.message_id
     LEFT JOIN users u ON u.id = m.created_by
     WHERE {$where}
     ORDER BY n.created_at DESC"
  );
  $st->execute($params);
  $inbox_items = $st->fetchAll();

  foreach ($inbox_items as $item) {
    $tid = (int)($item['thread_id'] ?? 0);
    if ($tid <= 0 && (int)($item['message_id'] ?? 0) > 0) {
      $tid = (int)$item['message_id'];
    }
    if ($tid > 0) {
      $thread_ids[] = $tid;
    }
  }
} elseif ($view === 'sent') {
  $st = $pdo->prepare(
    "SELECT m.id, m.entity_type, m.entity_id, m.title, m.thread_id, m.parent_id, m.body, m.status, m.message_type,
            m.created_at, m.created_by,
            COALESCE(r.total_recipients, 0) AS total_recipients,
            COALESCE(nr.read_count, 0) AS read_count,
            COALESCE(nr.unread_count, 0) AS unread_count
     FROM ts_messages m
     LEFT JOIN (
       SELECT message_id, COUNT(*) AS total_recipients
       FROM ts_message_recipients
       GROUP BY message_id
     ) r ON r.message_id = m.id
     LEFT JOIN (
       SELECT message_id,
              SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_count,
              SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
       FROM ts_notifications
       WHERE type = 'assigned'
       GROUP BY message_id
     ) nr ON nr.message_id = m.id
     WHERE m.created_by = ?
     ORDER BY m.created_at DESC"
  );
  $st->execute([$user_id]);
  $sent_items = $st->fetchAll();

  foreach ($sent_items as $item) {
    $tid = (int)($item['thread_id'] ?? 0);
    if ($tid <= 0 && (int)($item['id'] ?? 0) > 0) {
      $tid = (int)$item['id'];
    }
    if ($tid > 0) {
      $thread_ids[] = $tid;
    }
  }
}

$thread_ids = array_values(array_unique(array_filter($thread_ids, static fn($id) => $id > 0)));
$thread_messages = [];
if ($thread_ids) {
  $placeholders = implode(',', array_fill(0, count($thread_ids), '?'));
  $threadSt = $pdo->prepare(
    "SELECT m.id, m.thread_id, m.parent_id, m.title, m.body, m.message_type, m.created_at, m.created_by,
            u.first_name, u.last_name, u.email
     FROM ts_messages m
     LEFT JOIN users u ON u.id = m.created_by
     WHERE m.thread_id IN ({$placeholders})
     ORDER BY m.created_at ASC"
  );
  $threadSt->execute($thread_ids);
  $threadRows = $threadSt->fetchAll();
  foreach ($threadRows as $row) {
    $tid = (int)$row['thread_id'];
    if (!isset($thread_messages[$tid])) {
      $thread_messages[$tid] = [];
    }
    $thread_messages[$tid][] = $row;
  }
}

$csrf = csrf_token();
$api = url_path('api/notifications.php');
$messages_api = url_path('api/messages.php');
$can_delete_messages = can_delete_messages();

$build_origin_url = static function (string $entity_type, int $entity_id, int $message_id): ?string {
  if ($entity_id <= 0) {
    return null;
  }
  if ($entity_type === 'product') {
    return url_path('product_view.php?id=' . $entity_id . '#msg-' . $message_id);
  }
  if ($entity_type === 'listado') {
    return url_path('list_view.php?id=' . $entity_id . '#msg-' . $message_id);
  }
  return null;
};
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Inbox</h2>
        <span class="muted">Mensajería instantánea</span>
      </div>
      <div class="inline-actions">
        <a class="btn<?= $view === 'inbox' && $filter === 'unread' ? '' : ' btn-ghost' ?>" href="<?= url_path('inbox.php?view=inbox&filter=unread') ?>">Inbox</a>
        <a class="btn<?= $view === 'inbox' && $filter === 'unread' ? '' : ' btn-ghost' ?>" href="<?= url_path('inbox.php?view=inbox&filter=unread') ?>">No leídas</a>
        <a class="btn<?= $view === 'inbox' && $filter === 'all' ? '' : ' btn-ghost' ?>" href="<?= url_path('inbox.php?view=inbox&filter=all') ?>">Todas</a>
        <a class="btn<?= $view === 'sent' ? '' : ' btn-ghost' ?>" href="<?= url_path('inbox.php?view=sent') ?>">Enviados</a>
        <a class="btn<?= $show_new_message ? '' : ' btn-ghost' ?>" href="<?= url_path('inbox.php?view=new') ?>">Nuevo mensaje</a>
      </div>
    </div>

    <?php if ($show_new_message): ?>
    <div class="card" id="instant-message-form">
      <div class="card-header">
        <h3 class="card-title">Mensaje instantáneo</h3>
      </div>
      <form class="stack instant-form" id="instantMessageForm" data-instant-message-form>
        <div class="form-error" data-instant-message-error hidden></div>
        <div class="instant-form-grid">
          <div class="instant-form-left">
            <label class="form-field">
              <span class="form-label">Tipo</span>
              <select class="form-control" name="message_type">
                <option value="observacion" selected>Observación</option>
                <option value="problema">Problema</option>
                <option value="consulta">Consulta</option>
                <option value="accion">Acción</option>
              </select>
            </label>
            <label class="form-field">
              <span class="form-label">Asignar a *</span>
              <select class="form-control" name="assigned_to_user_ids[]" multiple required size="10">
                <?php foreach ($users as $msg_user): ?>
                  <?php $msg_user_name = trim((string)($msg_user['first_name'] ?? '') . ' ' . (string)($msg_user['last_name'] ?? '')); ?>
                  <option value="<?= (int)$msg_user['id'] ?>">
                    <?= e($msg_user_name !== '' ? $msg_user_name : (string)$msg_user['email']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="muted">Mantené Ctrl/Cmd para seleccionar varios usuarios.</small>
            </label>
          </div>
          <div class="instant-form-right">
            <label class="form-field">
              <span class="form-label">Título *</span>
              <input class="form-control" type="text" name="title" maxlength="160" required>
            </label>
            <label class="form-field instant-message-field">
              <span class="form-label">Mensaje *</span>
              <textarea class="form-control" name="body" rows="8" maxlength="5000" required></textarea>
            </label>
          </div>
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Enviar</button>
          <span class="muted" data-instant-message-result></span>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($show_notifications): ?>
    <div class="card">
      <div class="card-header messages-header">
        <h3 class="card-title"><?= $view === 'sent' ? 'Enviados' : 'Notificaciones' ?></h3>
      </div>

      <div class="notifications-list" data-notifications-list>
        <?php if ($view === 'inbox'): ?>
          <?php if (!$inbox_items): ?>
            <div class="muted">No hay notificaciones.</div>
          <?php else: ?>
            <?php foreach ($inbox_items as $item): ?>
              <?php
                $message_id = (int)($item['message_id'] ?? 0);
                $thread_id = (int)($item['thread_id'] ?? 0);
                if ($thread_id <= 0) {
                  $thread_id = $message_id;
                }
                $is_unread = (int)$item['is_read'] === 0;
                $author = trim((string)$item['first_name'] . ' ' . (string)$item['last_name']);
                if ($author === '') {
                  $author = (string)($item['email'] ?? 'Sistema');
                }
                $conversation = $thread_messages[$thread_id] ?? [];
                $origin_url = $build_origin_url((string)($item['entity_type'] ?? ''), (int)($item['entity_id'] ?? 0), $message_id);
              ?>
              <details class="notification-item<?= $is_unread ? ' is-unread' : '' ?>">
                <summary class="notification-summary">
                  <div class="notification-meta">
                    <strong><?= e((string)($item['title'] ?? 'Sin título')) ?></strong>
                    <span>por <?= e($author) ?></span>
                    <span><?= e((string)$item['notification_created_at']) ?></span>
                    <span class="message-badge"><?= e((string)($item['message_type'] ?? 'observacion')) ?></span>
                  </div>
                </summary>
                <div class="notification-body">
                  <?php if ($conversation): ?>
                    <div class="thread-list">
                      <?php foreach ($conversation as $thread_message): ?>
                        <?php
                          $thread_author = trim((string)$thread_message['first_name'] . ' ' . (string)$thread_message['last_name']);
                          if ($thread_author === '') {
                            $thread_author = (string)($thread_message['email'] ?? 'Sistema');
                          }
                        ?>
                        <div class="thread-item">
                          <div class="notification-meta">
                            <strong><?= e((string)($thread_message['title'] ?? 'Sin título')) ?></strong>
                            <span><?= e($thread_author) ?></span>
                            <span><?= e((string)$thread_message['created_at']) ?></span>
                          </div>
                          <div><?= nl2br(e((string)($thread_message['body'] ?? ''))) ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div><?= nl2br(e((string)($item['body'] ?? ''))) ?></div>
                  <?php endif; ?>
                  <div class="notification-actions">
                    <?php if ($is_unread): ?>
                      <button class="btn" type="button" data-mark-read data-notification-id="<?= (int)$item['notification_id'] ?>">Marcar como leído</button>
                    <?php endif; ?>
                    <?php if ($origin_url !== null): ?>
                      <a class="btn btn-ghost" href="<?= e($origin_url) ?>">Ir al origen</a>
                    <?php endif; ?>
                    <button class="btn btn-ghost" type="button" data-reply-toggle>Responder</button>
                    <?php if ($can_delete_messages): ?>
                      <button
                        class="btn btn-danger notification-actions-danger"
                        type="button"
                        data-delete-message
                        data-message-id="<?= $message_id ?>"
                        data-thread-id="<?= $thread_id ?>"
                      >
                        Eliminar
                      </button>
                    <?php endif; ?>
                  </div>
                  <form class="message-form is-hidden" data-reply-form data-message-id="<?= $message_id ?>">
                    <input type="hidden" name="entity_type" value="user">
                    <input type="hidden" name="entity_id" value="<?= (int)$item['created_by'] ?>">
                    <input type="hidden" name="thread_id" value="<?= $thread_id ?>">
                    <input type="hidden" name="parent_id" value="<?= $message_id ?>">
                    <input type="hidden" name="assigned_to_user_ids[]" value="<?= (int)$item['created_by'] ?>">
                    <label class="form-field">
                      <span class="form-label">Título *</span>
                      <input class="form-control" type="text" name="title" maxlength="160" value="Re: <?= e((string)($item['title'] ?? 'Sin título')) ?>" required>
                    </label>
                    <label class="form-field">
                      <span class="form-label">Respuesta *</span>
                      <textarea class="form-control" name="body" rows="5" maxlength="5000" required></textarea>
                    </label>
                    <div class="inline-actions">
                      <button class="btn" type="submit">Enviar respuesta</button>
                      <span class="muted" data-reply-result></span>
                    </div>
                  </form>
                </div>
              </details>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php else: ?>
          <?php if (!$sent_items): ?>
            <div class="muted">No hay mensajes enviados.</div>
          <?php else: ?>
            <?php foreach ($sent_items as $item): ?>
              <?php
                $message_id = (int)$item['id'];
                $thread_id = (int)($item['thread_id'] ?? 0);
                if ($thread_id <= 0) {
                  $thread_id = $message_id;
                }
                $conversation = $thread_messages[$thread_id] ?? [];
              ?>
              <details class="notification-item">
                <summary class="notification-summary">
                  <div class="notification-meta">
                    <strong><?= e((string)($item['title'] ?? 'Sin título')) ?></strong>
                    <span><?= e((string)$item['created_at']) ?></span>
                    <span class="message-badge"><?= e((string)($item['message_type'] ?? 'observacion')) ?></span>
                    <span class="message-badge">Leídos: <?= (int)$item['read_count'] ?></span>
                    <span class="message-badge">No leídos: <?= (int)$item['unread_count'] ?></span>
                    <span class="message-badge">Destinatarios: <?= (int)$item['total_recipients'] ?></span>
                  </div>
                </summary>
                <div class="notification-body">
                  <?php if ($conversation): ?>
                    <div class="thread-list">
                      <?php foreach ($conversation as $thread_message): ?>
                        <?php
                          $thread_author = trim((string)$thread_message['first_name'] . ' ' . (string)$thread_message['last_name']);
                          if ($thread_author === '') {
                            $thread_author = (string)($thread_message['email'] ?? 'Sistema');
                          }
                        ?>
                        <div class="thread-item">
                          <div class="notification-meta">
                            <strong><?= e((string)($thread_message['title'] ?? 'Sin título')) ?></strong>
                            <span><?= e($thread_author) ?></span>
                            <span><?= e((string)$thread_message['created_at']) ?></span>
                          </div>
                          <div><?= nl2br(e((string)($thread_message['body'] ?? ''))) ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div><?= nl2br(e((string)($item['body'] ?? ''))) ?></div>
                  <?php endif; ?>
                  <?php if ($can_delete_messages): ?>
                    <div class="notification-actions">
                      <span class="notification-actions-spacer"></span>
                      <button
                        class="btn btn-danger notification-actions-danger"
                        type="button"
                        data-delete-message
                        data-message-id="<?= $message_id ?>"
                        data-thread-id="<?= $thread_id ?>"
                      >
                        Eliminar
                      </button>
                    </div>
                  <?php endif; ?>
                </div>
              </details>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>

<script>
  (() => {
    const api = <?= json_encode($api, JSON_UNESCAPED_UNICODE) ?>;
    const messagesApi = <?= json_encode($messages_api, JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

    const markRead = async (payload) => {
      await fetch(`${api}?action=mark_read`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString(),
        credentials: 'same-origin',
      });
      window.location.reload();
    };

    document.querySelectorAll('[data-mark-read]').forEach((button) => {
      button.addEventListener('click', () => {
        const params = new URLSearchParams({
          notification_id: button.dataset.notificationId,
          csrf_token: csrfToken,
        });
        markRead(params);
      });
    });

    const instantForm = document.querySelector('[data-instant-message-form]');
    if (instantForm) {
      const result = instantForm.querySelector('[data-instant-message-result]');
      const errorBox = instantForm.querySelector('[data-instant-message-error]');
      const assigneeField = instantForm.querySelector('select[name="assigned_to_user_ids[]"]');
      const typeField = instantForm.querySelector('select[name="message_type"]');
      const titleField = instantForm.querySelector('input[name="title"]');
      const bodyField = instantForm.querySelector('textarea[name="body"]');

      const showFormError = (message) => {
        if (!errorBox) {
          alert(message);
          return;
        }
        errorBox.textContent = message;
        errorBox.hidden = false;
      };

      const clearFormError = () => {
        if (!errorBox) {
          return;
        }
        errorBox.textContent = '';
        errorBox.hidden = true;
      };

      if (!assigneeField || !typeField || !titleField || !bodyField) {
        showFormError('No se pudo inicializar el formulario de mensaje instantáneo.');
        return;
      }

      instantForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        clearFormError();
        if (result) {
          result.textContent = '';
        }

        const selectedValues = Array.from(assigneeField.selectedOptions || []).map((option) => option.value).filter(Boolean);
        const selected = selectedValues;

        if (selected.length === 0) {
          showFormError('Debés seleccionar al menos un destinatario.');
          assigneeField.focus();
          return;
        }
        if (!titleField.value.trim()) {
          showFormError('El título es obligatorio.');
          titleField.focus();
          return;
        }
        if (!bodyField.value.trim()) {
          showFormError('El mensaje es obligatorio.');
          bodyField.focus();
          return;
        }

        const formData = new FormData(instantForm);
        formData.set('entity_type', 'user');
        formData.set('entity_id', selected[0] || '1');
        formData.set('require_assignee', '1');
        formData.set('message_type', typeField.value);
        formData.set('title', titleField.value.trim());
        formData.set('body', bodyField.value.trim());
        formData.set('message', bodyField.value.trim());
        formData.set('send_to_all', '0');
        formData.set('csrf_token', csrfToken);
        formData.delete('assigned_to_user_ids[]');
        selected.forEach((value) => formData.append('assigned_to_user_ids[]', value));

        try {
          const response = await fetch(`${messagesApi}?action=create`, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
          });
          const raw = await response.text();
          let data = null;
          try {
            data = raw ? JSON.parse(raw) : null;
          } catch (parseError) {
            data = null;
          }

          if (!data) {
            showFormError('Respuesta inválida del servidor.');
            return;
          }

          if (!response.ok || !data.ok) {
            showFormError(data.error || 'No se pudo enviar el mensaje.');
            return;
          }
        } catch (error) {
          const message = error instanceof Error && error.message
            ? `Error de conexión: ${error.message}`
            : 'Error de conexión: no se pudo contactar al servidor.';
          showFormError(message);
          return;
        }

        bodyField.value = '';
        titleField.value = '';
        typeField.value = 'observacion';
        Array.from(assigneeField.options).forEach((option) => {
          option.selected = false;
        });
        if (result) {
          result.textContent = 'Mensaje enviado correctamente.';
        }
      });
    }

    document.querySelectorAll('[data-reply-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        const details = button.closest('details');
        if (!details) {
          return;
        }
        const form = details.querySelector('[data-reply-form]');
        if (!form) {
          return;
        }
        form.classList.toggle('is-hidden');
        const textarea = form.querySelector('textarea[name="body"]');
        if (textarea) {
          textarea.value = '';
        }
      });
    });

    document.querySelectorAll('[data-reply-form]').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = new URLSearchParams({ csrf_token: csrfToken, require_assignee: '1' });
        Array.from(new FormData(form).entries()).forEach(([key, value]) => {
          payload.append(key, String(value));
        });
        const response = await fetch(`${messagesApi}?action=create`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
          credentials: 'same-origin',
        });
        const data = await response.json();
        const result = form.querySelector('[data-reply-result]');
        if (!data.ok) {
          if (result) {
            result.textContent = data.error || 'No se pudo enviar la respuesta.';
          }
          return;
        }
        window.location.reload();
      });
    });

    document.querySelectorAll('[data-delete-message]').forEach((button) => {
      button.addEventListener('click', async () => {
        const messageId = button.dataset.messageId;
        const threadId = button.dataset.threadId;
        if (!messageId) {
          return;
        }
        const shouldDelete = window.confirm('Esta acción eliminará el mensaje de forma permanente. ¿Deseás continuar?');
        if (!shouldDelete) {
          return;
        }
        const payload = new URLSearchParams({
          csrf_token: csrfToken,
          message_id: messageId,
          thread_id: threadId || '',
        });
        const response = await fetch(`${messagesApi}?action=delete`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
          credentials: 'same-origin',
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          window.alert(data.error || 'No se pudo eliminar el mensaje.');
          return;
        }
        window.location.reload();
      });
    });

  })();
</script>
</body>
</html>
