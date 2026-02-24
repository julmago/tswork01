<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/stock.php';
require_once __DIR__ . '/include/stock_sync.php';
require_once __DIR__ . '/prestashop.php';
require_login();
require_permission(can_sync_prestashop());

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id de listado.');

$st = db()->prepare("SELECT * FROM stock_lists WHERE id = ? LIMIT 1");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');
if ($list['status'] !== 'open') abort(400, 'Este listado está cerrado y no se puede sincronizar.');

$st = db()->prepare("
  SELECT p.id AS product_id, p.sku, p.name, i.qty, i.synced_qty
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY p.name ASC
");
$st->execute([$list_id]);
$items = $st->fetchAll();

$sites = stock_sync_push_enabled_sites();
$site_names = [];
foreach ($sites as $site) {
  $site_id = (int)($site['id'] ?? 0);
  $site_name = trim((string)($site['name'] ?? 'Sitio #' . $site_id));
  if ($site_name === '') {
    $site_name = 'Sitio #' . $site_id;
  }
  $site_names[$site_id] = $site_name;
}

$results = [];
$ok = 0; $fail = 0; $skip = 0; $omitted = 0;
$total_sent = 0;
$pending_after_total = 0;

$upsertProgress = db()->prepare("INSERT INTO list_site_sync_progress (list_id, product_id, site_id, synced_qty, target_qty, status, last_error, updated_at)
  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
  ON DUPLICATE KEY UPDATE
    synced_qty = VALUES(synced_qty),
    target_qty = VALUES(target_qty),
    status = VALUES(status),
    last_error = VALUES(last_error),
    updated_at = NOW()");
$stProg = db()->prepare('SELECT synced_qty, target_qty, status FROM list_site_sync_progress WHERE list_id = ? AND product_id = ? AND site_id = ? LIMIT 1');

foreach ($items as $it) {
  $sku = (string)$it['sku'];
  $qty = (int)$it['qty'];
  $synced_qty = min((int)$it['synced_qty'], $qty);

  try {
    $product_id = (int)$it['product_id'];
    $productFailed = false;
    $productApplicable = 0;
    $productDone = 0;
    $productSentNow = 0;
    $productPendingBefore = 0;
    $productPendingAfter = 0;
    $detailChunks = [];

    if (count($sites) === 0) {
      $results[] = [
        'sku' => $sku,
        'name' => $it['name'],
        'qty' => 0,
        'status' => 'OMITIDO',
        'progress' => $synced_qty . '/' . $qty,
        'detail' => 'No hay sitios habilitados para push (BIDIR o TS→Sitio).',
      ];
      $omitted++;
      continue;
    }

    foreach ($sites as $site) {
      $siteId = (int)($site['id'] ?? 0);
      $siteName = $site_names[$siteId] ?? ('Sitio #' . $siteId);
      $targetQty = $qty;

      $app = stock_sync_site_product_applicability($site, $product_id, $sku);
      if (!($app['applicable'] ?? false)) {
        $upsertProgress->execute([$list_id, $product_id, $siteId, 0, 0, 'na', (string)($app['reason'] ?? ''), null]);
        $omitted++;
        $detailChunks[] = $siteName . ': N/A';
        continue;
      }

      $productApplicable++;

      $stProg->execute([$list_id, $product_id, $siteId]);
      $progressRow = $stProg->fetch();
      $currentSynced = $progressRow ? max(0, (int)$progressRow['synced_qty']) : 0;
      $toSync = max(0, $targetQty - $currentSynced);
      $productPendingBefore += $toSync;
      $productPendingAfter += $toSync;

      if ($toSync <= 0) {
        $upsertProgress->execute([$list_id, $product_id, $siteId, $targetQty, $targetQty, 'done', null]);
        $productDone++;
        $detailChunks[] = $siteName . ': OK (' . $targetQty . '/' . $targetQty . ')';
        continue;
      }

      $connType = stock_sync_conn_type($site);
      $siteErrors = [];

      if ($connType === 'prestashop') {
        $psBaseUrl = trim((string)($site['ps_base_url'] ?? ''));
        $psApiKey = trim((string)($site['ps_api_key'] ?? ''));

        if ($psBaseUrl === '' || $psApiKey === '') {
          $err = 'Configuración incompleta de PrestaShop (URL/API Key).';
          $upsertProgress->execute([$list_id, $product_id, $siteId, $currentSynced, $targetQty, 'na', $err]);
          $omitted++;
          $detailChunks[] = $siteName . ': OMITIDO (' . $err . ')';
          continue;
        }

        try {
          $matches = ps_find_by_reference_all($sku, $psBaseUrl, $psApiKey);
          if (count($matches) === 0) {
            $siteErrors[] = 'No existe en PrestaShop por reference/SKU.';
          } else {
            $match = $matches[0];
            $id_product = (int)($match['id_product'] ?? 0);
            $id_attr = (int)($match['id_product_attribute'] ?? 0);

            $id_sa = ps_find_stock_available_id_with_credentials($id_product, $id_attr, $psBaseUrl, $psApiKey);
            if (!$id_sa) {
              try {
                $id_sa = ps_create_stock_available_with_credentials($id_product, $id_attr, 0, $psBaseUrl, $psApiKey);
              } catch (Throwable $stock_error) {
                $siteErrors[] = 'El producto existe pero no tiene stock creado en PrestaShop.';
              }
            }

            if ($id_sa) {
              $remoteStock = ps_get_stock_available_with_credentials((int)$id_sa, $psBaseUrl, $psApiKey);
              $remoteQty = max(0, (int)($remoteStock['qty'] ?? 0));
              $newRemoteQty = $remoteQty + $toSync;
              ps_update_stock_available_quantity_with_credentials((int)$id_sa, $newRemoteQty, $psBaseUrl, $psApiKey);
            }
          }
        } catch (Throwable $psError) {
          $siteErrors[] = $psError->getMessage();
        }
      } else {
        if (!stock_sync_site_has_credentials($site)) {
          $err = 'MercadoLibre sin credenciales válidas.';
          $upsertProgress->execute([$list_id, $product_id, $siteId, $currentSynced, $targetQty, 'error', $err]);
          $productFailed = true;
          $detailChunks[] = $siteName . ': ERROR (' . $err . ')';
          continue;
        }

        $links = stock_sync_load_ml_links(db(), $siteId, $product_id);
        if (count($links) === 0) {
          $err = 'Sin vínculo (Item ID).';
          $upsertProgress->execute([$list_id, $product_id, $siteId, 0, 0, 'na', $err]);
          $omitted++;
          $detailChunks[] = $siteName . ': N/A';
          continue;
        }

        foreach ($links as $link) {
          $mlItemId = trim((string)($link['ml_item_id'] ?? ''));
          $mlVariationId = trim((string)($link['ml_variation_id'] ?? ''));
          if ($mlVariationId === '') {
            $mlVariationId = null;
          }
          if ($mlItemId === '') {
            $siteErrors[] = 'Vínculo sin Item ID.';
            continue;
          }

          try {
            $itemResponse = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/items/' . rawurlencode($mlItemId));
            $itemResponse = ['code' => (int)$itemResponse['code'], 'body' => (string)$itemResponse['raw']];
            $itemStatus = (int)($itemResponse['code'] ?? 0);
            $itemBody = (string)($itemResponse['body'] ?? '');
            if ($itemStatus < 200 || $itemStatus >= 300) {
              $siteErrors[] = 'No se pudo leer item ML (HTTP ' . $itemStatus . ').';
              continue;
            }

            $itemJson = json_decode($itemBody, true);
            if (!is_array($itemJson)) {
              $siteErrors[] = 'Respuesta inválida de ML al consultar item.';
              continue;
            }

            if ($mlVariationId !== null) {
              $variationFound = null;
              foreach ((array)($itemJson['variations'] ?? []) as $variation) {
                if ((string)($variation['id'] ?? '') === (string)$mlVariationId) {
                  $variationFound = $variation;
                  break;
                }
              }
              if ($variationFound === null) {
                $siteErrors[] = 'No se encontró la variación vinculada en ML.';
                continue;
              }

              $remoteQty = max(0, (int)($variationFound['available_quantity'] ?? 0));
              $newRemoteQty = $remoteQty + $toSync;
              $putBody = ['available_quantity' => $newRemoteQty];
              $putResponse = ml_api_request($siteId, 'PUT', 'https://api.mercadolibre.com/items/' . rawurlencode($mlItemId) . '/variations/' . rawurlencode((string)$mlVariationId), $putBody);
              $putCode = (int)($putResponse['code'] ?? 0);
              if ($putCode < 200 || $putCode >= 300) {
                $siteErrors[] = 'Error ML variación (HTTP ' . $putCode . ').';
              }
            } else {
              $remoteQty = max(0, (int)($itemJson['available_quantity'] ?? 0));
              $newRemoteQty = $remoteQty + $toSync;
              $putBody = ['available_quantity' => $newRemoteQty];
              $putResponse = ml_api_request($siteId, 'PUT', 'https://api.mercadolibre.com/items/' . rawurlencode($mlItemId), $putBody);
              $putCode = (int)($putResponse['code'] ?? 0);
              if ($putCode < 200 || $putCode >= 300) {
                $siteErrors[] = 'Error ML item (HTTP ' . $putCode . ').';
              }
            }
          } catch (Throwable $mlError) {
            $siteErrors[] = $mlError->getMessage();
          }
        }
      }

      if (count($siteErrors) > 0) {
        $err = implode(' | ', $siteErrors);
        $upsertProgress->execute([$list_id, $product_id, $siteId, $currentSynced, $targetQty, 'error', $err]);
        $productFailed = true;
        $detailChunks[] = $siteName . ': ERROR (' . $err . ')';
      } else {
        $upsertProgress->execute([$list_id, $product_id, $siteId, $targetQty, $targetQty, 'done', null]);
        $productDone++;
        $productSentNow += $toSync;
        $productPendingAfter -= $toSync;
        $detailChunks[] = $siteName . ': OK (' . $targetQty . '/' . $targetQty . ')';
      }
    }

    if ($productPendingBefore <= 0) {
      $skip++;
      $status = 'SIN PENDIENTE';
      $progress = $synced_qty . '/' . $qty;
      $detailChunks[] = 'No hay unidades nuevas para enviar.';
    } elseif ($productApplicable > 0 && $productDone >= $productApplicable && !$productFailed) {
      $total_sent += $productSentNow;
      $ok++;
      $status = 'OK';
      $progress = $synced_qty . '/' . $qty;
    } elseif ($productApplicable === 0) {
      $omitted++;
      $status = 'OMITIDO';
      $progress = $synced_qty . '/' . $qty;
    } else {
      $fail++;
      $status = 'ERROR';
      $progress = $synced_qty . '/' . $qty;
    }

    $pending_after_total += max(0, $productPendingAfter);

    $results[] = [
      'sku' => $sku,
      'name' => $it['name'],
      'qty' => $productSentNow,
      'status' => $status,
      'progress' => $progress,
      'detail' => implode(' | ', $detailChunks),
    ];
  } catch (Throwable $t) {
    if (count($sites) > 0) {
      $pending_after_total += $qty * count($sites);
    }
    $results[] = [
      'sku' => $sku,
      'name' => $it['name'],
      'qty' => 0,
      'status' => 'ERROR',
      'progress' => $synced_qty . '/' . $qty,
      'detail' => $t->getMessage(),
    ];
    $fail++;
  }
}

$synced = $fail === 0 && $total_sent > 0;
if ($total_sent > 0) {
  $st = db()->prepare("UPDATE stock_lists SET sync_target='all', synced_at=NOW() WHERE id = ?");
  $st->execute([$list_id]);
}
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
      <h2 class="page-title">Sincronización completa (Todos los canales)</h2>
      <span class="muted">Listado #<?= (int)$list_id ?></span>
    </div>

    <div class="card stack">
      <div class="form-row">
        <div><strong>Total enviados en esta sincronización:</strong> <?= (int)$total_sent ?></div>
        <div><strong>Pendiente después de sincronizar:</strong> <?= (int)$pending_after_total ?></div>
        <div><strong>Productos omitidos:</strong> <?= (int)$omitted ?></div>
      </div>

      <?php if ($synced): ?>
        <div class="alert alert-success"><strong>OK:</strong> sincronización completa.</div>
      <?php else: ?>
        <div class="alert alert-danger"><strong>Atención:</strong> hubo errores (<?= (int)$fail ?>).</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>SKU</th><th>Nombre</th><th>Enviado</th><th>Progreso</th><th>Resultado</th><th>Detalle</th></tr>
          </thead>
          <tbody>
            <?php foreach ($results as $r): ?>
              <tr>
                <td><?= e($r['sku']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= (int)$r['qty'] ?></td>
                <td><?= e($r['progress']) ?></td>
                <td>
                  <?php if ($r['status'] === 'OK'): ?>
                    <span class="badge badge-success"><?= e($r['status']) ?></span>
                  <?php elseif ($r['status'] === 'SIN PENDIENTE' || $r['status'] === 'OMITIDO'): ?>
                    <span class="badge badge-muted"><?= e($r['status']) ?></span>
                  <?php else: ?>
                    <span class="badge badge-danger"><?= e($r['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e($r['detail']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$results): ?>
              <tr><td colspan="6">El listado no tiene items.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions">
        <a class="btn btn-ghost" href="list_view.php?id=<?= (int)$list_id ?>">Volver al listado</a>
      </div>
    </div>
  </div>
</main>
</body>
</html>
