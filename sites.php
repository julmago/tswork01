<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/stock_sync.php';
require_login();
require_permission(hasPerm('sites_access'), 'Sin permiso para acceder a Sitios.');
ensure_sites_schema();
ensure_stock_sync_schema();

$pdo = db();
$q = trim(get('q', ''));
$page = max(1, (int)get('page', '1'));
$limit = 25;
$offset = ($page - 1) * $limit;

$error = '';
$message = '';

$canSitesActionsView = hasPerm('sites_actions_view');
$canSitesEditData = hasPerm('sites_edit_data');
$canSitesTestConnection = hasPerm('sites_test_connection');
$canSitesBulkImportExport = hasPerm('sites_bulk_import_export');


function normalize_channel_type($value): string {
  $channel = strtoupper(trim((string)$value));
  if (!in_array($channel, ['NONE', 'PRESTASHOP', 'MERCADOLIBRE'], true)) {
    return 'NONE';
  }
  return $channel;
}




function normalize_stock_sync_mode($value, int $syncStockEnabled): string {
  $mode = strtoupper(trim((string)$value));
  if (in_array($mode, ['OFF', 'BIDIR', 'TS_TO_SITE', 'SITE_TO_TS'], true)) {
    return $mode;
  }
  return $syncStockEnabled === 1 ? 'BIDIR' : 'OFF';
}

function ml_default_callback_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if ($host === '') {
    return url_path('ml_oauth_callback.php');
  }
  return $scheme . '://' . $host . url_path('ml_oauth_callback.php');
}

if (is_post()) {

  $action = post('action');

  if ($action === 'create_site') {
    require_permission($canSitesEditData, 'Sin permiso para modificar sitios.');
    $name = trim(post('name'));
    $channelType = normalize_channel_type(post('channel_type', 'NONE'));
    $margin = normalize_site_margin_percent_value(post('margin_percent'));
    $isActive = post('is_active') === '1' ? 1 : 0;
    $showInList = post('is_visible', '1') === '0' ? 0 : 1;
    $showInProduct = post('show_in_product', '1') === '0' ? 0 : 1;
    $showSync = post('show_sync', '0') === '1' ? 1 : 0;
    $connectionEnabled = post('connection_enabled', '0') === '1' ? 1 : 0;
    $syncStockEnabledLegacy = post('sync_stock_enabled', '0') === '1' ? 1 : 0;
    $stockSyncMode = normalize_stock_sync_mode(post('stock_sync_mode', ''), $syncStockEnabledLegacy);
    $syncStockEnabled = $stockSyncMode === 'OFF' ? 0 : 1;
    $psBaseUrl = trim(post('ps_base_url'));
    $psApiKey = trim(post('ps_api_key'));
    $webhookSecret = trim(post('webhook_secret'));
    $psShopIdRaw = trim(post('ps_shop_id'));
    $psShopId = $psShopIdRaw === '' ? null : (int)$psShopIdRaw;
    $mlClientId = trim(post('ml_client_id'));
    $mlClientSecret = trim(post('ml_client_secret'));
    $mlRedirectUri = trim(post('ml_redirect_uri'));
    $mlNotificationSecret = trim(post('ml_notification_secret'));

    if ($channelType === 'NONE') {
      $connectionEnabled = 0;
    }

    if ($name === '') {
      $error = 'Ingresá el nombre del sitio.';
    } elseif (mb_strlen($name) > 80) {
      $error = 'El nombre del sitio no puede superar los 80 caracteres.';
    } elseif ($margin === null) {
      $error = 'Margen (%) inválido. Usá un valor entre -100 y 999.99.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
        $st->execute([$name]);
        if ($st->fetch()) {
          $error = 'Ese sitio ya existe.';
        } else {
          $st = $pdo->prepare('INSERT INTO sites(name, channel_type, conn_type, conn_enabled, sync_stock_enabled, stock_sync_mode, margin_percent, is_active, is_visible, show_in_product, show_sync, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
          $st->execute([$name, $channelType, strtolower($channelType), $connectionEnabled, $syncStockEnabled, $stockSyncMode, $margin, $isActive, $showInList, $showInProduct, $showSync]);
          $siteId = (int)$pdo->lastInsertId();
          $effectiveMlRedirectUri = $mlRedirectUri !== '' ? $mlRedirectUri : ml_default_callback_url();
          $st = $pdo->prepare("INSERT INTO site_connections (site_id, channel_type, enabled, ps_base_url, ps_api_key, webhook_secret, ps_shop_id, ml_client_id, ml_client_secret, ml_redirect_uri, ml_notification_secret, ml_access_token, ml_refresh_token, ml_token_expires_at, ml_connected_at, ml_user_id, ml_status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, 'DISCONNECTED', NOW())
            ON DUPLICATE KEY UPDATE
              channel_type = VALUES(channel_type),
              enabled = VALUES(enabled),
              ps_base_url = VALUES(ps_base_url),
              ps_api_key = VALUES(ps_api_key),
              webhook_secret = VALUES(webhook_secret),
              ps_shop_id = VALUES(ps_shop_id),
              ml_client_id = VALUES(ml_client_id),
              ml_client_secret = VALUES(ml_client_secret),
              ml_redirect_uri = VALUES(ml_redirect_uri),
              ml_notification_secret = VALUES(ml_notification_secret),
              updated_at = NOW(),
              ml_access_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_access_token END,
              ml_refresh_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_refresh_token END,
              ml_token_expires_at = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_token_expires_at END,
              ml_connected_at = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_connected_at END,
              ml_user_id = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_user_id END,
              ml_status = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN 'DISCONNECTED' ELSE site_connections.ml_status END");
          $st->execute([
            $siteId,
            $channelType,
            $connectionEnabled,
            $psBaseUrl !== '' ? $psBaseUrl : null,
            $psApiKey !== '' ? $psApiKey : null,
            $webhookSecret !== '' ? $webhookSecret : null,
            $psShopId,
            $mlClientId !== '' ? $mlClientId : null,
            $mlClientSecret !== '' ? $mlClientSecret : null,
            $effectiveMlRedirectUri,
            $mlNotificationSecret !== '' ? $mlNotificationSecret : null,
          ]);
          header('Location: sites.php?created=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo crear el sitio.';
      }
    }
  }

  if ($action === 'update_site') {
    require_permission($canSitesEditData, 'Sin permiso para modificar sitios.');
    $id = (int)post('id', '0');
    $name = trim(post('name'));
    $channelType = normalize_channel_type(post('channel_type', 'NONE'));
    $margin = normalize_site_margin_percent_value(post('margin_percent'));
    $isActive = post('is_active') === '1' ? 1 : 0;
    $showInList = post('is_visible', '1') === '0' ? 0 : 1;
    $showInProduct = post('show_in_product', '1') === '0' ? 0 : 1;
    $showSync = post('show_sync', '0') === '1' ? 1 : 0;
    $connectionEnabled = post('connection_enabled', '0') === '1' ? 1 : 0;
    $syncStockEnabledLegacy = post('sync_stock_enabled', '0') === '1' ? 1 : 0;
    $stockSyncMode = normalize_stock_sync_mode(post('stock_sync_mode', ''), $syncStockEnabledLegacy);
    $syncStockEnabled = $stockSyncMode === 'OFF' ? 0 : 1;
    $psBaseUrl = trim(post('ps_base_url'));
    $psApiKey = trim(post('ps_api_key'));
    $webhookSecret = trim(post('webhook_secret'));
    $psShopIdRaw = trim(post('ps_shop_id'));
    $psShopId = $psShopIdRaw === '' ? null : (int)$psShopIdRaw;
    $mlClientId = trim(post('ml_client_id'));
    $mlClientSecret = trim(post('ml_client_secret'));
    $mlRedirectUri = trim(post('ml_redirect_uri'));
    $mlNotificationSecret = trim(post('ml_notification_secret'));

    if ($channelType === 'NONE') {
      $connectionEnabled = 0;
    }

    if ($id <= 0) {
      $error = 'Sitio inválido.';
    } elseif ($name === '') {
      $error = 'Ingresá el nombre del sitio.';
    } elseif (mb_strlen($name) > 80) {
      $error = 'El nombre del sitio no puede superar los 80 caracteres.';
    } elseif ($margin === null) {
      $error = 'Margen (%) inválido. Usá un valor entre -100 y 999.99.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
        $st->execute([$name, $id]);
        if ($st->fetch()) {
          $error = 'Ese sitio ya existe.';
        } else {
          $st = $pdo->prepare('UPDATE sites SET name = ?, channel_type = ?, conn_type = ?, conn_enabled = ?, sync_stock_enabled = ?, stock_sync_mode = ?, margin_percent = ?, is_active = ?, is_visible = ?, show_in_product = ?, show_sync = ?, updated_at = NOW() WHERE id = ?');
          $st->execute([$name, $channelType, strtolower($channelType), $connectionEnabled, $syncStockEnabled, $stockSyncMode, $margin, $isActive, $showInList, $showInProduct, $showSync, $id]);
          $effectiveMlRedirectUri = $mlRedirectUri !== '' ? $mlRedirectUri : ml_default_callback_url();
          $st = $pdo->prepare("INSERT INTO site_connections (site_id, channel_type, enabled, ps_base_url, ps_api_key, webhook_secret, ps_shop_id, ml_client_id, ml_client_secret, ml_redirect_uri, ml_notification_secret, ml_access_token, ml_refresh_token, ml_token_expires_at, ml_connected_at, ml_user_id, ml_status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, 'DISCONNECTED', NOW())
            ON DUPLICATE KEY UPDATE
              channel_type = VALUES(channel_type),
              enabled = VALUES(enabled),
              ps_base_url = VALUES(ps_base_url),
              ps_api_key = VALUES(ps_api_key),
              webhook_secret = VALUES(webhook_secret),
              ps_shop_id = VALUES(ps_shop_id),
              ml_client_id = VALUES(ml_client_id),
              ml_client_secret = VALUES(ml_client_secret),
              ml_redirect_uri = VALUES(ml_redirect_uri),
              ml_notification_secret = VALUES(ml_notification_secret),
              updated_at = NOW(),
              ml_access_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_access_token END,
              ml_refresh_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_refresh_token END,
              ml_token_expires_at = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_token_expires_at END,
              ml_connected_at = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_connected_at END,
              ml_user_id = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_user_id END,
              ml_status = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN 'DISCONNECTED' ELSE site_connections.ml_status END");
          $st->execute([
            $id,
            $channelType,
            $connectionEnabled,
            $psBaseUrl !== '' ? $psBaseUrl : null,
            $psApiKey !== '' ? $psApiKey : null,
            $webhookSecret !== '' ? $webhookSecret : null,
            $psShopId,
            $mlClientId !== '' ? $mlClientId : null,
            $mlClientSecret !== '' ? $mlClientSecret : null,
            $effectiveMlRedirectUri,
            $mlNotificationSecret !== '' ? $mlNotificationSecret : null,
          ]);
          header('Location: sites.php?updated=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo modificar el sitio.';
      }
    }
  }

  if ($action === 'toggle_site') {
    require_permission($canSitesEditData, 'Sin permiso para modificar sitios.');
    $id = (int)post('id', '0');
    if ($id > 0) {
      try {
        $st = $pdo->prepare('UPDATE sites SET is_active = (1 - is_active), updated_at = NOW() WHERE id = ?');
        $st->execute([$id]);
        header('Location: sites.php?toggled=1');
        exit;
      } catch (Throwable $t) {
        $error = 'No se pudo cambiar el estado del sitio.';
      }
    }
  }
}

if (get('created') === '1') {
  $message = 'Sitio creado.';
}
if (get('updated') === '1') {
  $message = 'Sitio modificado.';
}
if (get('toggled') === '1') {
  $message = 'Estado del sitio actualizado.';
}
if (get('oauth_connected') === '1') {
  $message = 'MercadoLibre conectado correctamente.';
}
if (get('oauth_error') !== '') {
  $error = 'No se pudo completar la conexión con MercadoLibre. Verificá la configuración e intentá nuevamente.';
}

$where = '';
$params = [];
if ($q !== '') {
  $where = 'WHERE s.name LIKE :q';
  $params[':q'] = '%' . $q . '%';
}

$countSql = "SELECT COUNT(*) FROM sites s $where";
$countSt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
  $countSt->bindValue($key, $value, PDO::PARAM_STR);
}
$countSt->execute();
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$listSql = "SELECT s.id, s.name, s.margin_percent, s.is_active, s.is_visible, s.show_in_product, s.sync_stock_enabled, s.stock_sync_mode
  FROM sites s
  $where
  ORDER BY s.name ASC
  LIMIT :limit OFFSET :offset";
$listSt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
  $listSt->bindValue($key, $value, PDO::PARAM_STR);
}
$listSt->bindValue(':limit', $limit, PDO::PARAM_INT);
$listSt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listSt->execute();
$sites = $listSt->fetchAll();

$editId = (int)get('edit_id', '0');
$editSite = null;
if ($editId > 0) {
  $st = $pdo->prepare('SELECT id, name, channel_type, conn_type, conn_enabled, sync_stock_enabled, stock_sync_mode, margin_percent, is_active, is_visible, show_in_product, show_sync FROM sites WHERE id = ? LIMIT 1');
  $st->execute([$editId]);
  $editSite = $st->fetch();
}

$editConnection = [
  'channel_type' => $editSite ? normalize_channel_type($editSite['channel_type'] ?? 'NONE') : 'NONE',
  'enabled' => (int)($editSite['conn_enabled'] ?? 0),
  'sync_stock_enabled' => (int)($editSite['sync_stock_enabled'] ?? 0),
  'stock_sync_mode' => (string)($editSite['stock_sync_mode'] ?? ((int)($editSite['sync_stock_enabled'] ?? 0) === 1 ? 'BIDIR' : 'OFF')),
  'ps_base_url' => '',
  'ps_api_key' => '',
  'webhook_secret' => '',
  'ps_shop_id' => '',
  'ml_client_id' => '',
  'ml_client_secret' => '',
  'ml_redirect_uri' => '',
  'ml_notification_secret' => '',
  'ml_access_token' => '',
  'ml_refresh_token' => '',
  'ml_token_expires_at' => '',
  'ml_connected_at' => '',
  'ml_user_id' => '',
  'ml_status' => 'DISCONNECTED',
];
if ($editSite) {
  $st = $pdo->prepare('SELECT site_id, channel_type, enabled, ps_base_url, ps_api_key, webhook_secret, ps_shop_id, ml_client_id, ml_client_secret, ml_redirect_uri, ml_notification_secret, ml_access_token, ml_refresh_token, ml_token_expires_at, ml_connected_at, ml_user_id, ml_status FROM site_connections WHERE site_id = ? LIMIT 1');
  $st->execute([(int)$editSite['id']]);
  $row = $st->fetch();
  if ($row) {
    $editConnection = [
      'channel_type' => normalize_channel_type($row['channel_type'] ?? $editConnection['channel_type']),
      'enabled' => (int)($row['enabled'] ?? 0),
      'sync_stock_enabled' => (int)($editSite['sync_stock_enabled'] ?? 0),
      'stock_sync_mode' => (string)($editSite['stock_sync_mode'] ?? ((int)($editSite['sync_stock_enabled'] ?? 0) === 1 ? 'BIDIR' : 'OFF')),
      'ps_base_url' => (string)($row['ps_base_url'] ?? ''),
      'ps_api_key' => (string)($row['ps_api_key'] ?? ''),
      'webhook_secret' => (string)($row['webhook_secret'] ?? ''),
      'ps_shop_id' => isset($row['ps_shop_id']) ? (string)$row['ps_shop_id'] : '',
      'ml_client_id' => (string)($row['ml_client_id'] ?? ''),
      'ml_client_secret' => (string)($row['ml_client_secret'] ?? ''),
      'ml_redirect_uri' => (string)($row['ml_redirect_uri'] ?? ''),
      'ml_notification_secret' => (string)($row['ml_notification_secret'] ?? ''),
      'ml_access_token' => (string)($row['ml_access_token'] ?? ''),
      'ml_refresh_token' => (string)($row['ml_refresh_token'] ?? ''),
      'ml_token_expires_at' => (string)($row['ml_token_expires_at'] ?? ''),
      'ml_connected_at' => (string)($row['ml_connected_at'] ?? ''),
      'ml_user_id' => (string)($row['ml_user_id'] ?? ''),
      'ml_status' => (string)($row['ml_status'] ?? 'DISCONNECTED'),
    ];
  }
}

$formConnection = $editConnection;
if (trim($formConnection['ml_redirect_uri']) === '') {
  $formConnection['ml_redirect_uri'] = ml_default_callback_url();
}
if (is_post() && $error !== '' && in_array(post('action'), ['create_site', 'update_site'], true)) {
  $formConnection = [
    'channel_type' => normalize_channel_type(post('channel_type', $editConnection['channel_type'])),
    'enabled' => post('connection_enabled', (string)$editConnection['enabled']) === '1' ? 1 : 0,
    'stock_sync_mode' => normalize_stock_sync_mode(post('stock_sync_mode', (string)($editConnection['stock_sync_mode'] ?? '')), post('sync_stock_enabled', (string)$editConnection['sync_stock_enabled']) === '1' ? 1 : 0),
    'sync_stock_enabled' => normalize_stock_sync_mode(post('stock_sync_mode', (string)($editConnection['stock_sync_mode'] ?? '')), post('sync_stock_enabled', (string)$editConnection['sync_stock_enabled']) === '1' ? 1 : 0) === 'OFF' ? 0 : 1,
    'ps_base_url' => trim(post('ps_base_url', $editConnection['ps_base_url'])),
    'ps_api_key' => trim(post('ps_api_key', $editConnection['ps_api_key'])),
    'webhook_secret' => trim(post('webhook_secret', $editConnection['webhook_secret'])),
    'ps_shop_id' => trim(post('ps_shop_id', $editConnection['ps_shop_id'])),
    'ml_client_id' => trim(post('ml_client_id', $editConnection['ml_client_id'])),
    'ml_client_secret' => trim(post('ml_client_secret', $editConnection['ml_client_secret'])),
    'ml_redirect_uri' => trim(post('ml_redirect_uri', $editConnection['ml_redirect_uri'])),
    'ml_notification_secret' => trim(post('ml_notification_secret', $editConnection['ml_notification_secret'])),
    'ml_access_token' => $editConnection['ml_access_token'],
    'ml_refresh_token' => $editConnection['ml_refresh_token'],
    'ml_token_expires_at' => $editConnection['ml_token_expires_at'],
    'ml_connected_at' => $editConnection['ml_connected_at'],
    'ml_user_id' => $editConnection['ml_user_id'],
    'ml_status' => $editConnection['ml_status'],
  ];
}

$showNewForm = get('new') === '1' || $editSite !== null;
$isEditReadOnly = $editSite && !$canSitesEditData;

$queryBase = [];
if ($q !== '') $queryBase['q'] = $q;
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);
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
        <h2 class="page-title">Sitios</h2>
        <span class="muted">Configurá márgenes por canal (extra %).</span>
      </div>
      <div class="inline-actions">
        <?php if ($showNewForm && !$editSite): ?>
          <a class="btn btn-ghost" href="sites.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
        <?php else: ?>
          <?php if ($canSitesEditData): ?>
            <a class="btn" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['new' => 1]))) ?>">Nuevo sitio</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="get" action="sites.php" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre">
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="sites.php">Limpiar</a><?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($showNewForm): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><?= $editSite ? 'Modificar sitio' : 'Nuevo sitio' ?></h3>
        </div>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="<?= $editSite ? 'update_site' : 'create_site' ?>">
          <?php if ($editSite): ?>
            <input type="hidden" name="id" value="<?= (int)$editSite['id'] ?>">
          <?php endif; ?>
          <?php if ($isEditReadOnly): ?><fieldset disabled><?php endif; ?>
          <div class="grid" style="grid-template-columns: minmax(240px, 1.2fr) minmax(320px, 1fr) minmax(320px, 1fr); gap: var(--space-4); align-items: end;">
            <label class="form-field">
              <span class="form-label">Nombre del sitio</span>
              <input class="form-control" type="text" name="name" maxlength="80" required value="<?= e($editSite ? (string)$editSite['name'] : '') ?>">
            </label>
            <div class="grid" style="grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: var(--space-3);">
              <label class="form-field">
                <span class="form-label">Margen (%)</span>
                <input class="form-control" type="number" name="margin_percent" min="-100" max="999.99" step="0.01" required value="<?= e($editSite ? number_format((float)$editSite['margin_percent'], 2, '.', '') : '0') ?>">
              </label>
              <label class="form-field">
                <span class="form-label">Estado</span>
                <select class="form-control" name="is_active">
                  <?php $activeValue = $editSite ? (int)$editSite['is_active'] : 1; ?>
                  <option value="1" <?= $activeValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $activeValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
            </div>
            <div class="grid" style="grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: var(--space-3);">
              <label class="form-field">
                <span class="form-label">Mostrar en lista</span>
                <select class="form-control" name="is_visible">
                  <?php $visibleValue = $editSite ? (int)$editSite['is_visible'] : 1; ?>
                  <option value="1" <?= $visibleValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $visibleValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
              <label class="form-field">
                <span class="form-label">Mostrar en producto</span>
                <select class="form-control" name="show_in_product">
                  <?php $showProductValue = $editSite ? (int)$editSite['show_in_product'] : 1; ?>
                  <option value="1" <?= $showProductValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $showProductValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
            </div>
          </div>
          <label class="form-field" style="max-width: 360px;">
            <span class="form-label">Tipo de conexión</span>
            <select class="form-control" name="channel_type" id="channel_type">
              <?php $channelTypeValue = $formConnection['channel_type']; ?>
              <option value="NONE" <?= $channelTypeValue === 'NONE' ? 'selected' : '' ?>>Sin conexión</option>
              <option value="PRESTASHOP" <?= $channelTypeValue === 'PRESTASHOP' ? 'selected' : '' ?>>PrestaShop</option>
              <option value="MERCADOLIBRE" <?= $channelTypeValue === 'MERCADOLIBRE' ? 'selected' : '' ?>>MercadoLibre</option>
            </select>
          </label>

          <div id="connFields" class="stack">
            <div class="grid" style="grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: var(--space-3); max-width: 520px;">
              <label class="form-field">
                <span class="form-label">Habilitado</span>
                <select class="form-control" name="connection_enabled">
                  <option value="1" <?= (int)$formConnection['enabled'] === 1 ? 'selected' : '' ?>>Sí</option>
                  <option value="0" <?= (int)$formConnection['enabled'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
              </label>
              <label class="form-field">
                <span class="form-label">Sincronizar stock</span>
                <select class="form-control" name="stock_sync_mode">
                  <?php $syncModeValue = normalize_stock_sync_mode((string)($formConnection['stock_sync_mode'] ?? ''), isset($formConnection['sync_stock_enabled']) ? (int)$formConnection['sync_stock_enabled'] : 0); ?>
                  <option value="OFF" <?= $syncModeValue === 'OFF' ? 'selected' : '' ?>>No</option>
                  <option value="BIDIR" <?= $syncModeValue === 'BIDIR' ? 'selected' : '' ?>>Bidireccional</option>
                  <option value="TS_TO_SITE" <?= $syncModeValue === 'TS_TO_SITE' ? 'selected' : '' ?>>TSWork → Sitio</option>
                  <option value="SITE_TO_TS" <?= $syncModeValue === 'SITE_TO_TS' ? 'selected' : '' ?>>Sitio → TSWork</option>
                </select>
              </label>
              <label class="form-field">
                <span class="form-label">Mostrar sincronización</span>
                <select class="form-control" name="show_sync">
                  <?php $showSyncValue = (is_post() && $error !== '' && in_array(post('action'), ['create_site', 'update_site'], true)) ? (post('show_sync', '0') === '1' ? 1 : 0) : ($editSite ? (int)($editSite['show_sync'] ?? 0) : 0); ?>
                  <option value="1" <?= $showSyncValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $showSyncValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
            </div>

            <div id="psFields" class="grid" style="grid-template-columns: repeat(3, minmax(220px, 1fr)); gap: var(--space-3);">
              <label class="form-field">
                <span class="form-label">URL base</span>
                <input class="form-control" type="text" name="ps_base_url" maxlength="255" value="<?= e($formConnection['ps_base_url']) ?>">
              </label>
              <label class="form-field">
                <span class="form-label">API Key / Token</span>
                <input class="form-control" type="text" name="ps_api_key" maxlength="255" value="<?= e($formConnection['ps_api_key']) ?>">
              </label>
              <label class="form-field">
                <span class="form-label">Webhook secret (HMAC)</span>
                <input class="form-control" type="text" name="webhook_secret" maxlength="255" value="<?= e($formConnection['webhook_secret']) ?>">
              </label>
              <label class="form-field">
                <span class="form-label">Shop ID (opcional)</span>
                <input class="form-control" type="number" name="ps_shop_id" min="0" step="1" value="<?= e($formConnection['ps_shop_id']) ?>">
              </label>
            </div>

            <div id="mlFields" class="stack" style="gap: var(--space-3);">
              <div class="grid" style="grid-template-columns: repeat(4, minmax(220px, 1fr)); gap: var(--space-3);">
                <label class="form-field">
                  <span class="form-label">Client ID</span>
                  <input class="form-control" type="text" name="ml_client_id" maxlength="100" value="<?= e($formConnection['ml_client_id']) ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Client Secret</span>
                  <input class="form-control" type="password" name="ml_client_secret" maxlength="255" value="<?= e($formConnection['ml_client_secret']) ?>" autocomplete="off">
                </label>
                <label class="form-field">
                  <span class="form-label">Redirect URI</span>
                  <input class="form-control" type="url" name="ml_redirect_uri" maxlength="255" readonly value="<?= e($formConnection['ml_redirect_uri']) ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Webhook secret ML</span>
                  <input class="form-control" type="text" name="ml_notification_secret" maxlength="255" value="<?= e($formConnection['ml_notification_secret']) ?>" placeholder="Opcional">
                </label>
              </div>
              <div class="grid" style="grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: var(--space-3);">
                <label class="form-field">
                  <span class="form-label">Callback URL</span>
                  <input class="form-control" type="url" readonly value="<?= e($formConnection['ml_redirect_uri']) ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Usuario ML (opcional)</span>
                  <input class="form-control" type="text" readonly value="<?= e($formConnection['ml_user_id']) ?>" placeholder="-">
                </label>
              </div>
              <div class="grid" style="grid-template-columns: minmax(220px, 1fr) auto; gap: var(--space-3); align-items: end;">
                <?php $isMlConnected = strtoupper(trim((string)$formConnection['ml_status'])) === 'CONNECTED'; ?>
                <label class="form-field">
                  <span class="form-label">Estado de conexión</span>
                  <input class="form-control" type="text" readonly value="<?= $isMlConnected ? 'Conectado' : 'No conectado' ?>">
                </label>
                <?php if ($editSite): ?>
                  <a class="btn" href="ml_oauth_auth.php?site_id=<?= (int)$editSite['id'] ?>">Conectar / Obtener-Actualizar Token</a>
                <?php else: ?>
                  <button class="btn" type="button" disabled title="Guardá el sitio para conectar MercadoLibre">Conectar / Obtener-Actualizar Token</button>
                <?php endif; ?>
              </div>
              <small class="muted">El refresh token se guarda automáticamente al conectar y se usa para renovar sesión. No se carga manualmente.</small>
            </div>
          </div>

          <?php if ($isEditReadOnly): ?></fieldset><?php endif; ?>
          <div class="inline-actions">
            <a class="btn btn-ghost" href="sites.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
            <?php if (!$isEditReadOnly && $canSitesEditData): ?>
              <button class="btn" type="submit"><?= $editSite ? 'Guardar' : 'Agregar' ?></button>
            <?php endif; ?>
          </div>
        </form>
      </div>

          <?php if ($editSite && $canSitesTestConnection): ?>
            <div class="card" id="siteSkuTestCard">
              <div class="card-header">
                <h3 class="card-title">Probar conexión / Probar SKU</h3>
              </div>
              <p class="muted small">Se usa la misma búsqueda que la sincronización según el tipo de conexión del sitio.</p>
              <form id="siteSkuTestForm" class="form-row" style="align-items:end;">
                <input type="hidden" id="siteSkuTestSiteId" value="<?= (int)$editSite['id'] ?>">
                <div class="form-group">
                  <label class="form-label" for="siteSkuTestInput">SKU</label>
                  <input class="form-control" type="text" id="siteSkuTestInput" placeholder="SKU" required>
                </div>
                <div class="form-group">
                  <button class="btn" type="submit" id="siteSkuTestSubmit">Probar</button>
                </div>
              </form>
              <div id="siteSkuTestMessage" class="muted small" style="margin-top: var(--space-3);"></div>
              <div class="table-wrapper" style="margin-top: var(--space-3); display:none;" id="siteSkuTestTableWrap">
                <table class="table" id="siteSkuTestTable">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Titulo</th>
                      <th>Precio</th>
                      <th>Stock</th>
                      <th>Item ID</th>
                      <th>Variation ID</th>
                      <th>Acciones</th>
                    </tr>
                  </thead>
                  <tbody id="siteSkuTestTbody"></tbody>
                </table>
              </div>
            </div>

            <?php if ($canSitesBulkImportExport): ?>
            <div class="card" id="siteStockBulkCard">
              <div class="card-header">
                <h3 class="card-title">Importar / Exportar stock masivo por SKU</h3>
              </div>
              <form id="siteStockBulkForm" class="grid" style="grid-template-columns: repeat(3, minmax(220px, 1fr)); gap: var(--space-3); align-items:end;">
                <input type="hidden" id="siteStockBulkSiteId" value="<?= (int)$editSite['id'] ?>">
                <label class="form-field">
                  <span class="form-label">Acción</span>
                  <select class="form-control" id="siteStockBulkAction" required>
                    <option value="import">Importar stock (Sitio → TSWork)</option>
                    <option value="export">Exportar stock (TSWork → Sitio)</option>
                  </select>
                </label>
                <label class="form-field">
                  <span class="form-label">Modo</span>
                  <select class="form-control" id="siteStockBulkMode" required>
                    <option value="set">Seteado (pisar)</option>
                    <option value="add">Sumar (sumar al existente)</option>
                  </select>
                </label>
                <div class="form-field">
                  <button class="btn" type="submit" id="siteStockBulkSubmit">Ejecutar</button>
                </div>
              </form>
              <div id="siteStockBulkMessage" class="muted small" style="margin-top: var(--space-3);"></div>
              <div id="siteStockBulkDebug" class="alert alert-warning" style="margin-top: var(--space-2); display:none; white-space:pre-wrap;"></div>
              <div class="table-wrapper" style="margin-top: var(--space-3); display:none;" id="siteStockBulkTableWrap">
                <table class="table" id="siteStockBulkTable">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Estado</th>
                      <th>Stock origen</th>
                      <th>Stock destino antes</th>
                      <th>Stock destino después</th>
                      <th>Mensaje</th>
                    </tr>
                  </thead>
                  <tbody id="siteStockBulkTbody"></tbody>
                </table>
              </div>
            </div>
            <?php endif; ?>
          <?php endif; ?>

    <?php endif; ?>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Margen (%)</th>
              <th>Estado</th>
              <th>Mostrar en lista</th>
              <th>Mostrar en producto</th>
              <?php if ($canSitesActionsView): ?><th>Acciones</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$sites): ?>
              <tr><td colspan="<?= $canSitesActionsView ? '6' : '5' ?>">Sin sitios.</td></tr>
            <?php else: ?>
              <?php foreach ($sites as $site): ?>
                <tr>
                  <td><?= e($site['name']) ?></td>
                  <td><?= e(number_format((float)$site['margin_percent'], 2, '.', '')) ?></td>
                  <td><?= (int)$site['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <td><?= (int)$site['is_visible'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <td><?= (int)$site['show_in_product'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <?php if ($canSitesActionsView): ?>
                    <td>
                      <div class="inline-actions">
                        <a class="btn btn-ghost btn-sm" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page, 'edit_id' => (int)$site['id']])) ) ?>">Modificar</a>
                      </div>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="inline-actions">
        <?php
          $prevQuery = $queryBase;
          $prevQuery['page'] = $prevPage;
          $nextQuery = $queryBase;
          $nextQuery['page'] = $nextPage;
        ?>
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost" href="sites.php?<?= e(http_build_query($prevQuery)) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int)$page ?> de <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-ghost" href="sites.php?<?= e(http_build_query($nextQuery)) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php if ($showNewForm): ?>
  <script>
    (function () {
      var channelType = document.getElementById('channel_type');
      var connFields = document.getElementById('connFields');
      var prestashopFields = document.getElementById('psFields');
      var mercadolibreFields = document.getElementById('mlFields');

      function toggleConnectionFields() {
        if (!channelType || !connFields || !prestashopFields || !mercadolibreFields) {
          return;
        }
        var value = channelType.value;
        if (value === 'NONE') {
          connFields.style.display = 'none';
          prestashopFields.style.display = 'none';
          mercadolibreFields.style.display = 'none';
          return;
        }
        connFields.style.display = '';
        prestashopFields.style.display = value === 'PRESTASHOP' ? '' : 'none';
        mercadolibreFields.style.display = value === 'MERCADOLIBRE' ? '' : 'none';
      }

      if (channelType) {
        channelType.addEventListener('change', toggleConnectionFields);
      }
      toggleConnectionFields();

      var siteStockBulkForm = document.getElementById('siteStockBulkForm');
      var siteStockBulkSiteId = document.getElementById('siteStockBulkSiteId');
      var siteStockBulkAction = document.getElementById('siteStockBulkAction');
      var siteStockBulkMode = document.getElementById('siteStockBulkMode');
      var siteStockBulkMessage = document.getElementById('siteStockBulkMessage');
      var siteStockBulkDebug = document.getElementById('siteStockBulkDebug');
      var siteStockBulkTableWrap = document.getElementById('siteStockBulkTableWrap');
      var siteStockBulkTbody = document.getElementById('siteStockBulkTbody');
      var siteStockBulkSubmit = document.getElementById('siteStockBulkSubmit');
      var siteStockBulkRunId = 0;
      var siteStockBulkStepOffset = 0;
      var siteStockBulkStepLimit = 300;

      var siteSkuTestForm = document.getElementById('siteSkuTestForm');
      var siteSkuTestInput = document.getElementById('siteSkuTestInput');
      var siteSkuTestSiteId = document.getElementById('siteSkuTestSiteId');
      var siteSkuTestMessage = document.getElementById('siteSkuTestMessage');
      var siteSkuTestTableWrap = document.getElementById('siteSkuTestTableWrap');
      var siteSkuTestTbody = document.getElementById('siteSkuTestTbody');
      var siteSkuTestSubmit = document.getElementById('siteSkuTestSubmit');

      function siteStockBulkClearTable() {
        if (siteStockBulkTbody) {
          siteStockBulkTbody.innerHTML = '';
        }
      }

      function siteStockBulkSetMessage(text, type) {
        if (!siteStockBulkMessage) return;
        siteStockBulkMessage.className = 'muted small';
        if (type === 'error') {
          siteStockBulkMessage.className = 'alert alert-danger';
        } else if (type === 'info') {
          siteStockBulkMessage.className = 'alert alert-warning';
        }
        siteStockBulkMessage.textContent = text;
      }

      function siteStockBulkRenderRows(rows) {
        if (!siteStockBulkTableWrap || !siteStockBulkTbody) {
          return;
        }
        siteStockBulkClearTable();
        siteStockBulkTableWrap.style.display = '';
        rows.forEach(function (row) {
          var tr = document.createElement('tr');
          var tdSku = document.createElement('td');
          tdSku.textContent = row.sku || '';
          var tdStatus = document.createElement('td');
          tdStatus.textContent = row.status || '';
          var tdOrigin = document.createElement('td');
          tdOrigin.textContent = Number.isFinite(Number(row.remote_qty_before)) ? String(parseInt(row.remote_qty_before, 10)) : '—';
          var tdBefore = document.createElement('td');
          tdBefore.textContent = Number.isFinite(Number(row.ts_qty_before)) ? String(parseInt(row.ts_qty_before, 10)) : '—';
          var tdAfter = document.createElement('td');
          tdAfter.textContent = Number.isFinite(Number(row.ts_qty_after)) ? String(parseInt(row.ts_qty_after, 10)) : (Number.isFinite(Number(row.remote_qty_after)) ? String(parseInt(row.remote_qty_after, 10)) : '—');
          var tdMessage = document.createElement('td');
          tdMessage.textContent = row.message || '';

          tr.appendChild(tdSku);
          tr.appendChild(tdStatus);
          tr.appendChild(tdOrigin);
          tr.appendChild(tdBefore);
          tr.appendChild(tdAfter);
          tr.appendChild(tdMessage);
          siteStockBulkTbody.appendChild(tr);
        });
      }

      function siteStockBulkHideDiagnostic() {
        if (!siteStockBulkDebug) {
          return;
        }
        siteStockBulkDebug.style.display = 'none';
        siteStockBulkDebug.textContent = '';
      }

      function siteStockBulkRenderDiagnostic(payload) {
        if (!siteStockBulkDebug) {
          return;
        }
        var debug = payload && payload.debug && typeof payload.debug === 'object' ? payload.debug : {};
        var total = payload && Number.isFinite(Number(payload.total_rows)) ? parseInt(payload.total_rows, 10) : 0;
        var processed = payload && Number.isFinite(Number(payload.processed_rows)) ? parseInt(payload.processed_rows, 10) : 0;
        var status = payload && payload.status ? String(payload.status) : '';
        var show = status === 'error' || (status === 'done' && total === 0 && processed === 0);
        if (!show) {
          siteStockBulkHideDiagnostic();
          return;
        }

        var lines = [
          'Diagnóstico',
          'URL consultada: ' + (debug.debug_last_url || '—'),
          'HTTP status: ' + (debug.debug_last_http ? String(parseInt(debug.debug_last_http, 10)) : '—'),
          'Preview body: ' + (debug.debug_last_body_preview || '—'),
          'Pages tried: ' + (debug.debug_pages_tried ? String(parseInt(debug.debug_pages_tried, 10)) : '0')
        ];
        siteStockBulkDebug.textContent = lines.join('\n');
        siteStockBulkDebug.style.display = '';
      }

      function siteStockBulkStatusMessage(payload) {
        var processed = payload && Number.isFinite(Number(payload.processed_rows)) ? parseInt(payload.processed_rows, 10) : 0;
        var total = payload && Number.isFinite(Number(payload.total_rows)) ? parseInt(payload.total_rows, 10) : 0;
        return 'Progreso: ' + processed + '/' + total;
      }

      function siteStockBulkRunStep() {
        if (!siteStockBulkRunId) {
          return Promise.resolve();
        }
        var body = new URLSearchParams();
        body.append('run_id', String(siteStockBulkRunId));
        body.append('offset', String(siteStockBulkStepOffset));
        body.append('limit', String(siteStockBulkStepLimit));

        return fetch('api/site_stock_bulk_step.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        })
          .then(function (response) { return response.json(); })
          .then(function (payload) {
            if (!payload || payload.ok !== true) {
              var stepError = payload && payload.error ? payload.error : 'No se pudo procesar el lote.';
              siteStockBulkSetMessage(stepError, 'error');
              siteStockBulkRenderDiagnostic(payload || {});
              return;
            }
            if (Array.isArray(payload.rows) && payload.rows.length > 0) {
              siteStockBulkRenderRows(payload.rows);
            }
            if (payload.status === 'error') {
              siteStockBulkSetMessage(siteStockBulkStatusMessage(payload) + ' · ' + (payload.last_error || 'Error en proceso.'), 'error');
              siteStockBulkRenderDiagnostic(payload);
              return;
            }
            if (payload.status === 'done') {
              siteStockBulkSetMessage(siteStockBulkStatusMessage(payload) + ' · Finalizado.', '');
              siteStockBulkRenderDiagnostic(payload);
              return;
            }

            siteStockBulkSetMessage(siteStockBulkStatusMessage(payload), '');
            siteStockBulkHideDiagnostic();
            siteStockBulkStepOffset += siteStockBulkStepLimit;
            return siteStockBulkRunStep();
          });
      }

      function siteSkuTestClearTable() {
        if (siteSkuTestTbody) {
          siteSkuTestTbody.innerHTML = '';
        }
      }

      function siteSkuTestSetMessage(text, type) {
        if (!siteSkuTestMessage) return;
        siteSkuTestMessage.className = 'muted small';
        if (type === 'error') {
          siteSkuTestMessage.className = 'alert alert-danger';
        } else if (type === 'info') {
          siteSkuTestMessage.className = 'alert alert-warning';
        }
        siteSkuTestMessage.textContent = text;
      }

      function siteSkuTestRenderRows(rows) {
        if (!siteSkuTestTableWrap || !siteSkuTestTbody) {
          return;
        }
        siteSkuTestClearTable();
        siteSkuTestTableWrap.style.display = '';
        rows.forEach(function (row) {
          var tr = document.createElement('tr');
          var tdSku = document.createElement('td');
          tdSku.textContent = row.sku || '';
          var tdTitle = document.createElement('td');
          tdTitle.textContent = row.title || '';
          var tdPrice = document.createElement('td');
          tdPrice.textContent = Number.isFinite(Number(row.price)) ? String(parseInt(row.price, 10)) : '0';
          var tdStock = document.createElement('td');
          tdStock.textContent = Number.isFinite(Number(row.stock)) ? String(parseInt(row.stock, 10)) : '0';
          var tdItemId = document.createElement('td');
          tdItemId.textContent = row.item_id || '';
          var tdVariationId = document.createElement('td');
          tdVariationId.textContent = row.variation_id || '';
          var tdActions = document.createElement('td');

          if (row.item_id) {
            var linkBtn = document.createElement('button');
            linkBtn.type = 'button';
            linkBtn.className = 'btn btn-ghost';
            linkBtn.textContent = 'Vincular';
            linkBtn.addEventListener('click', function () {
              linkBtn.disabled = true;
              var body = new URLSearchParams();
              body.append('site_id', String(siteSkuTestSiteId.value || ''));
              body.append('sku', String(siteSkuTestInput.value || '').trim());
              body.append('item_id', String(row.item_id || ''));
              body.append('variation_id', String(row.variation_id || ''));

              fetch('api/site_link_product_ml.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
              })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                  if (!payload || payload.ok !== true) {
                    var msg = payload && payload.error ? payload.error : 'No se pudo vincular.';
                    siteSkuTestSetMessage(msg, 'error');
                    return;
                  }
                  siteSkuTestSetMessage('Vinculación guardada para SKU ' + (payload.product_sku || '') + '.', '');
                })
                .catch(function () {
                  siteSkuTestSetMessage('No se pudo vincular.', 'error');
                })
                .finally(function () {
                  linkBtn.disabled = false;
                });
            });
            tdActions.appendChild(linkBtn);
          } else {
            tdActions.textContent = '—';
          }

          tr.appendChild(tdSku);
          tr.appendChild(tdTitle);
          tr.appendChild(tdPrice);
          tr.appendChild(tdStock);
          tr.appendChild(tdItemId);
          tr.appendChild(tdVariationId);
          tr.appendChild(tdActions);
          siteSkuTestTbody.appendChild(tr);
        });
      }

      if (siteStockBulkForm && siteStockBulkSiteId && siteStockBulkAction && siteStockBulkMode) {
        siteStockBulkForm.addEventListener('submit', function (event) {
          event.preventDefault();
          if (siteStockBulkSubmit) {
            siteStockBulkSubmit.disabled = true;
          }
          siteStockBulkRunId = 0;
          siteStockBulkStepOffset = 0;
          siteStockBulkHideDiagnostic();
          siteStockBulkSetMessage('Preparando snapshot remoto...', '');
          if (siteStockBulkTableWrap) {
            siteStockBulkTableWrap.style.display = 'none';
          }

          var body = new URLSearchParams();
          body.append('site_id', String(siteStockBulkSiteId.value || ''));
          body.append('action', String(siteStockBulkAction.value || 'import'));
          body.append('mode', String(siteStockBulkMode.value || 'set'));

          fetch('api/site_stock_bulk_start.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
          })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
              if (!payload || payload.ok !== true) {
                var errorText = payload && payload.error ? payload.error : 'No se pudo iniciar el proceso masivo.';
                siteStockBulkSetMessage(errorText, 'error');
                siteStockBulkRenderDiagnostic(payload || {});
                return Promise.resolve();
              }
              siteStockBulkRunId = parseInt(payload.run_id || 0, 10) || 0;
              siteStockBulkSetMessage(siteStockBulkStatusMessage(payload), '');
              siteStockBulkRenderDiagnostic(payload);
              return siteStockBulkRunStep();
            })
            .catch(function () {
              siteStockBulkSetMessage('No se pudo ejecutar el proceso masivo.', 'error');
              siteStockBulkHideDiagnostic();
            })
            .finally(function () {
              if (siteStockBulkSubmit) {
                siteStockBulkSubmit.disabled = false;
              }
            });
        });
      }

      if (siteSkuTestForm && siteSkuTestInput && siteSkuTestSiteId) {
        siteSkuTestForm.addEventListener('submit', function (event) {
          event.preventDefault();
          var sku = siteSkuTestInput.value.trim();
          var siteId = siteSkuTestSiteId.value;
          if (!sku) {
            siteSkuTestSetMessage('Ingresá un SKU para probar.', 'error');
            if (siteSkuTestTableWrap) siteSkuTestTableWrap.style.display = 'none';
            return;
          }
          if (siteSkuTestSubmit) {
            siteSkuTestSubmit.disabled = true;
          }
          siteSkuTestSetMessage('Consultando...', '');
          if (siteSkuTestTableWrap) siteSkuTestTableWrap.style.display = 'none';

          var url = 'api/site_test_sku.php?site_id=' + encodeURIComponent(siteId) + '&sku=' + encodeURIComponent(sku);
          fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
              if (!payload || payload.ok !== true) {
                var errorText = payload && payload.error ? payload.error : 'No se pudo probar el SKU.';
                siteSkuTestSetMessage(errorText, 'error');
                if (siteSkuTestTableWrap) siteSkuTestTableWrap.style.display = 'none';
                return;
              }
              var rows = Array.isArray(payload.rows) ? payload.rows : [];
              if (rows.length === 0) {
                siteSkuTestSetMessage('Sin resultados para ese SKU.', 'info');
                if (siteSkuTestTableWrap) siteSkuTestTableWrap.style.display = 'none';
                return;
              }
              siteSkuTestSetMessage('Resultados encontrados: ' + rows.length + '.', '');
              siteSkuTestRenderRows(rows);
            })
            .catch(function () {
              siteSkuTestSetMessage('No se pudo probar el SKU.', 'error');
              if (siteSkuTestTableWrap) siteSkuTestTableWrap.style.display = 'none';
            })
            .finally(function () {
              if (siteSkuTestSubmit) {
                siteSkuTestSubmit.disabled = false;
              }
            });
        });
      }

    })();
  </script>
<?php endif; ?>
</body>
</html>
