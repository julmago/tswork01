<?php
declare(strict_types=1);

function ts_messages_block(string $entity_type, int $entity_id, array $options = []): void {
  $allowed = ['product', 'listado', 'pedido', 'proveedor', 'user'];
  if (!in_array($entity_type, $allowed, true) || $entity_id <= 0) {
    return;
  }
  $title = $options['title'] ?? 'Mensajes';
  $accordion = (bool)($options['accordion'] ?? false);
  $container_id = 'messages-block-' . $entity_type . '-' . $entity_id;
  $accordion_button_id = $container_id . '-toggle';
  $accordion_panel_id = $container_id . '-panel';
  $csrf = csrf_token();
  $api_base = url_path('api/messages.php');
  $notifications_api = url_path('api/notifications.php');
  $pdo = db();
  $users_st = $pdo->query('SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY first_name, last_name, email');
  $users = $users_st ? $users_st->fetchAll() : [];
  ?>
  <div class="card messages-block" id="<?= e($container_id) ?>" data-messages-block
       data-entity-type="<?= e($entity_type) ?>" data-entity-id="<?= (int)$entity_id ?>"
       data-api-base="<?= e($api_base) ?>" data-notifications-api="<?= e($notifications_api) ?>"
       data-csrf="<?= e($csrf) ?>" data-accordion="<?= $accordion ? '1' : '0' ?>">
    <?php if ($accordion): ?>
      <div class="card-header messages-accordion-header">
        <button class="messages-accordion-toggle" type="button"
                id="<?= e($accordion_button_id) ?>"
                aria-expanded="false"
                aria-controls="<?= e($accordion_panel_id) ?>"
                data-messages-accordion-toggle>
          <span>
            <h3 class="card-title"><?= e($title) ?></h3>
            <span class="muted small" data-open-count>Mensajes (0 sin archivar)</span>
          </span>
          <span class="messages-accordion-chevron" aria-hidden="true"></span>
        </button>
      </div>
      <div class="messages-accordion-panel" id="<?= e($accordion_panel_id) ?>" role="region"
           aria-labelledby="<?= e($accordion_button_id) ?>" data-messages-accordion-panel hidden>
    <?php else: ?>
    <div class="card-header messages-header">
      <div>
        <h3 class="card-title"><?= e($title) ?></h3>
        <span class="muted small" data-open-count>Mensajes (0 sin archivar)</span>
      </div>
      <?php endif; ?>
      <div class="messages-filters" role="tablist">
        <button class="messages-filter-btn is-active" type="button" data-filter="all">Todos</button>
        <button class="messages-filter-btn" type="button" data-filter="open">Abiertos</button>
        <button class="messages-filter-btn" type="button" data-filter="mine">Míos</button>
        <button class="messages-filter-btn" type="button" data-filter="mentioned">Mencionado</button>
        <button class="messages-filter-btn" type="button" data-filter="archived">Archivados</button>
      </div>
    <?php if (!$accordion): ?>
    </div>
    <?php endif; ?>
    <div class="messages-timeline" data-messages-list>
      <div class="muted">Cargando mensajes...</div>
    </div>
    <form class="stack instant-form" data-message-form>
      <div class="form-error" data-message-form-error hidden></div>
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
              <?php foreach ($users as $user): ?>
                <?php $user_name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')); ?>
                <option value="<?= (int)$user['id'] ?>">
                  <?= e($user_name !== '' ? $user_name : (string)$user['email']) ?>
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
        <span class="muted" data-message-form-result></span>
      </div>
    </form>
    <?php if ($accordion): ?>
      </div>
    <?php endif; ?>
  </div>
  <?php

  ts_messages_block_assets();
}

function ts_messages_block_assets(): void {
  static $printed = false;
  if ($printed) {
    return;
  }
  $printed = true;
  ?>
  <script>
    (() => {
      const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
      };

      const formatDate = (value) => {
        if (!value) return '';
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString();
      };

      const statusLabels = {
        abierto: 'Abierto',
        en_proceso: 'En proceso',
        resuelto: 'Resuelto',
        archivado: 'Archivado',
      };

      const typeLabels = {
        observacion: 'Observación',
        problema: 'Problema',
        consulta: 'Consulta',
        accion: 'Acción',
      };

      const blocks = document.querySelectorAll('[data-messages-block]');
      blocks.forEach((block) => {
        const apiBase = block.dataset.apiBase;
        const entityType = block.dataset.entityType;
        const entityId = block.dataset.entityId;
        const csrfToken = block.dataset.csrf;
        const list = block.querySelector('[data-messages-list]');
        const openCount = block.querySelector('[data-open-count]');
        const form = block.querySelector('[data-message-form]');
        const formError = block.querySelector('[data-message-form-error]');
        const formResult = block.querySelector('[data-message-form-result]');
        const filterButtons = block.querySelectorAll('[data-filter]');
        const isAccordion = block.dataset.accordion === '1';
        const accordionToggle = block.querySelector('[data-messages-accordion-toggle]');
        const accordionPanel = block.querySelector('[data-messages-accordion-panel]');
        const storageKey = `messages-accordion:${entityType}:${entityId}`;
        let activeFilter = 'all';

        const setAccordionOpen = (isOpen) => {
          if (!isAccordion || !accordionToggle || !accordionPanel) {
            return;
          }
          accordionToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          accordionPanel.hidden = !isOpen;
        };

        const getStoredAccordionState = () => {
          if (!isAccordion) {
            return null;
          }
          try {
            const value = window.localStorage.getItem(storageKey);
            if (value === 'open') return true;
            if (value === 'closed') return false;
          } catch (error) {
            return null;
          }
          return null;
        };

        const saveAccordionState = (isOpen) => {
          if (!isAccordion) {
            return;
          }
          try {
            window.localStorage.setItem(storageKey, isOpen ? 'open' : 'closed');
          } catch (error) {
            // ignore localStorage errors
          }
        };

        const showFormError = (message) => {
          if (!formError) {
            alert(message);
            return;
          }
          formError.textContent = message;
          formError.hidden = false;
        };

        const clearFormError = () => {
          if (!formError) {
            return;
          }
          formError.textContent = '';
          formError.hidden = true;
        };

        const setActiveFilter = (filter) => {
          activeFilter = filter;
          filterButtons.forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.filter === filter);
          });
        };

        const focusHashMessage = () => {
          const hash = window.location.hash || '';
          if (!hash.startsWith('#msg-')) {
            return;
          }
          const target = list.querySelector(hash);
          if (!target) {
            return;
          }
          target.scrollIntoView({ behavior: 'smooth', block: 'center' });
          target.classList.add('is-highlighted');
          window.setTimeout(() => target.classList.remove('is-highlighted'), 2600);
        };

        const renderList = (items) => {
          const nonArchivedTotal = items.filter((item) => item.status !== 'archivado').length;
          openCount.textContent = `Mensajes (${nonArchivedTotal} sin archivar)`;

          if (isAccordion) {
            const stored = getStoredAccordionState();
            const shouldOpen = nonArchivedTotal > 0 ? true : (stored ?? false);
            setAccordionOpen(shouldOpen);
          }

          if (!items.length) {
            list.innerHTML = '<div class="muted">Sin mensajes todavía.</div>';
            return;
          }

          list.innerHTML = items.map((item) => {
            const author = `${item.author_name ?? ''}`.trim();
            const badges = `
              <div class="message-badge">${escapeHtml(statusLabels[item.status] ?? item.status)}</div>
              <div class="message-badge">${escapeHtml(typeLabels[item.message_type] ?? item.message_type)}</div>
            `;
            const actions = item.can_edit ? `
              <div class="message-actions">
                <label class="form-label">Estado</label>
                <select data-message-status data-message-id="${item.id}">
                  ${['abierto','en_proceso','resuelto','archivado'].map((status) => `
                    <option value="${status}" ${status === item.status ? 'selected' : ''}>
                      ${escapeHtml(statusLabels[status] ?? status)}
                    </option>
                  `).join('')}
                </select>
                <button class="btn btn-ghost" type="button" data-archive-message data-message-id="${item.id}">Archivar</button>
              </div>
            ` : '';
            return `
              <div class="message-item" id="msg-${item.id}" data-message-id="${item.id}">
                <div class="message-meta">
                  <span><strong>${escapeHtml(item.title || 'Sin título')}</strong></span>
                  <span>${escapeHtml(author || 'Usuario')}</span>
                  <span>${escapeHtml(formatDate(item.created_at))}</span>
                </div>
                <div class="message-badges">${badges}</div>
                <div>${escapeHtml(item.body)}</div>
                ${item.assigned_to_user_id ? `<div class="muted small"><strong>Asignado a:</strong> ${escapeHtml(item.assigned_to_name || ('Usuario #' + item.assigned_to_user_id))}</div>` : ''}
                ${actions}
              </div>
            `;
          }).join('');

          list.querySelectorAll('[data-message-status]').forEach((select) => {
            select.addEventListener('change', async (event) => {
              const target = event.currentTarget;
              await updateStatus(target.dataset.messageId, target.value);
            });
          });

          list.querySelectorAll('[data-archive-message]').forEach((button) => {
            button.addEventListener('click', async (event) => {
              const target = event.currentTarget;
              await archiveMessage(target.dataset.messageId);
            });
          });

          focusHashMessage();
        };

        const fetchList = async () => {
          const params = new URLSearchParams({
            entity_type: entityType,
            entity_id: entityId,
          });
          if (activeFilter === 'open') {
            params.set('status', 'open');
          }
          if (activeFilter === 'mine') {
            params.set('mine', '1');
          }
          if (activeFilter === 'mentioned') {
            params.set('mentioned', '1');
          }
          if (activeFilter === 'archived') {
            params.set('status', 'archivado');
          }
          const response = await fetch(`${apiBase}?${params.toString()}`, { credentials: 'same-origin' });
          const data = await response.json();
          if (!data.ok) {
            list.innerHTML = `<div class="muted">${escapeHtml(data.error || 'No se pudieron cargar los mensajes.')}</div>`;
            return;
          }
          renderList(data.items || []);
        };

        const updateStatus = async (messageId, status) => {
          const body = new URLSearchParams({
            message_id: messageId,
            status,
            csrf_token: csrfToken,
          });
          await fetch(`${apiBase}?action=status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
          });
          fetchList();
        };

        const archiveMessage = async (messageId) => {
          const body = new URLSearchParams({
            message_id: messageId,
            csrf_token: csrfToken,
          });
          await fetch(`${apiBase}?action=archive`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
          });
          fetchList();
        };

        filterButtons.forEach((button) => {
          button.addEventListener('click', () => {
            setActiveFilter(button.dataset.filter);
            fetchList();
          });
        });

        if (isAccordion && accordionToggle) {
          accordionToggle.addEventListener('click', () => {
            const isOpen = accordionToggle.getAttribute('aria-expanded') === 'true';
            const next = !isOpen;
            setAccordionOpen(next);
            saveAccordionState(next);
          });
          setAccordionOpen(getStoredAccordionState() ?? false);
        }

        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          clearFormError();
          if (formResult) {
            formResult.textContent = '';
          }

          const assigneeField = form.querySelector('select[name="assigned_to_user_ids[]"]');
          const typeField = form.querySelector('select[name="message_type"]');
          const titleField = form.querySelector('input[name="title"]');
          const bodyField = form.querySelector('textarea[name="body"]');

          const selected = Array.from(assigneeField?.selectedOptions || []).map((option) => option.value).filter(Boolean);
          if (selected.length === 0) {
            showFormError('Debés seleccionar al menos un destinatario.');
            assigneeField?.focus();
            return;
          }
          if (!titleField?.value.trim()) {
            showFormError('El título es obligatorio.');
            titleField?.focus();
            return;
          }
          if (!bodyField?.value.trim()) {
            showFormError('El mensaje es obligatorio.');
            bodyField?.focus();
            return;
          }

          const payload = new FormData(form);
          payload.set('entity_type', entityType);
          payload.set('entity_id', entityId);
          payload.set('require_assignee', '1');
          payload.set('message_type', typeField?.value || 'observacion');
          payload.set('title', titleField.value.trim());
          payload.set('body', bodyField.value.trim());
          payload.set('message', bodyField.value.trim());
          payload.set('send_to_all', '0');
          payload.set('csrf_token', csrfToken);
          payload.delete('assigned_to_user_ids[]');
          selected.forEach((value) => payload.append('assigned_to_user_ids[]', value));

          try {
            const response = await fetch(`${apiBase}?action=create`, {
              method: 'POST',
              body: payload,
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
          if (typeField) {
            typeField.value = 'observacion';
          }
          Array.from(assigneeField?.options || []).forEach((option) => {
            option.selected = false;
          });
          if (formResult) {
            formResult.textContent = 'Mensaje enviado correctamente.';
          }
          fetchList();
        });

        setActiveFilter('all');
        fetchList();
      });
    })();
  </script>
  <?php
}
