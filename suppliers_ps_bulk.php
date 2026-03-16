<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prestashop.php';
require_once __DIR__ . '/include/stock_sync.php';

require_login();
if (!can_suppliers_ps_bulk()) {
  abort403();
}

ensure_stock_sync_schema();

$bulkDebugEnabled = defined('DEBUG') && DEBUG;

$pdo = db();
$supplierId = (int)($_SESSION['ps_bulk_supplier_id'] ?? 0);
$siteId = (int)($_SESSION['ps_bulk_site_id'] ?? 0);
$csvPath = (string)($_SESSION['ps_bulk_csv_path'] ?? '');

if ($supplierId <= 0 || $siteId <= 0 || $csvPath === '' || !is_file($csvPath)) {
  abort(400, 'La sesión del proceso masivo expiró o es inválida.');
}

$st = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$supplierId]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$siteSt = $pdo->prepare("SELECT s.id, s.name, s.conn_type, s.conn_enabled, sc.ps_base_url, sc.ps_api_key
  FROM sites s
  INNER JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.id = ? LIMIT 1");
$siteSt->execute([$siteId]);
$site = $siteSt->fetch();
if (!$site || strtolower((string)$site['conn_type']) !== 'prestashop' || (int)$site['conn_enabled'] !== 1) {
  abort(400, 'Sitio PrestaShop inválido para este proceso.');
}

$psBaseUrl = trim((string)($site['ps_base_url'] ?? ''));
$psApiKey = trim((string)($site['ps_api_key'] ?? ''));

function ps_bulk_detect_delimiter(string $line): string {
  $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
  arsort($counts);
  $top = array_key_first($counts);
  return $top !== null ? (string)$top : ',';
}

function ps_bulk_parse_csv_first_column(string $path): array {
  $rawLines = file($path, FILE_IGNORE_NEW_LINES);
  if (!is_array($rawLines) || count($rawLines) === 0) {
    return [];
  }

  $delimiter = ps_bulk_detect_delimiter((string)$rawLines[0]);
  $rows = [];
  foreach ($rawLines as $line) {
    if (trim($line) === '') {
      continue;
    }
    $cols = str_getcsv($line, $delimiter);
    $value = trim((string)($cols[0] ?? ''));
    if ($value !== '') {
      $rows[] = $value;
    }
  }

  if (count($rows) === 0) {
    return [];
  }

  $first = strtolower(trim($rows[0]));
  if (in_array($first, ['sku', 'supplier_sku', 'supplier sku', 'sku proveedor', 'supplier-sku'], true)) {
    array_shift($rows);
  }

  return $rows;
}

function ps_bulk_reference_candidates(array $item): array {
  $candidates = [];
  $tsw = trim((string)($item['sku'] ?? ''));
  $sup = trim((string)($item['supplier_sku'] ?? ''));

  if ($tsw !== '') {
    $candidates[] = $tsw;
  }
  if ($sup !== '' && $sup !== $tsw) {
    $candidates[] = $sup;
  }

  if ($sup !== '') {
    if (str_starts_with($sup, 'SS-')) {
      $withoutPrefix = substr($sup, 3);
      if ($withoutPrefix !== '' && !in_array($withoutPrefix, $candidates, true)) {
        $candidates[] = $withoutPrefix;
      }
    } else {
      $withPrefix = 'SS-' . $sup;
      if (!in_array($withPrefix, $candidates, true)) {
        $candidates[] = $withPrefix;
      }
    }
  }

  return $candidates;
}

function ps_bulk_resolve_product_id(array $item, string $baseUrl, string $apiKey, ?array &$testedCandidates = null): ?int {
  $candidates = ps_bulk_reference_candidates($item);
  $testedCandidates = $candidates;

  foreach ($candidates as $candidate) {
    $results = ps_find_by_reference_all($candidate, $baseUrl, $apiKey);
    if (empty($results)) {
      continue;
    }

    foreach ($results as $result) {
      if (($result['type'] ?? '') === 'product' && (int)($result['id_product'] ?? 0) > 0) {
        return (int)$result['id_product'];
      }
    }

    $id = (int)($results[0]['id_product'] ?? 0);
    if ($id > 0) {
      return $id;
    }
  }

  return null;
}

$csvSkus = ps_bulk_parse_csv_first_column($csvPath);
if (count($csvSkus) === 0) {
  abort(400, 'El CSV no contiene SKUs válidos en la primera columna.');
}

$matchSt = $pdo->prepare('SELECT p.id, p.sku, p.name, ps.supplier_sku
  FROM product_suppliers ps
  INNER JOIN products p ON p.id = ps.product_id
  WHERE ps.supplier_id = ? AND ps.supplier_sku = ?
  LIMIT 1');

$found = [];
$notFound = [];
foreach ($csvSkus as $skuProv) {
  $matchSt->execute([$supplierId, $skuProv]);
  $row = $matchSt->fetch();
  if ($row) {
    $productId = (int)$row['id'];
    if (!isset($found[$productId])) {
      $found[$productId] = [
        'product_id' => $productId,
        'supplier_sku' => (string)$row['supplier_sku'],
        'sku' => (string)$row['sku'],
        'name' => (string)$row['name'],
      ];
    }
  } else {
    $notFound[] = $skuProv;
  }
}

$foundProductIds = array_map(static fn(array $item): int => (int)$item['product_id'], array_values($found));
$nonListedDetectedCount = 0;
if (count($foundProductIds) > 0) {
  $placeholders = implode(',', array_fill(0, count($foundProductIds), '?'));
  $countSt = $pdo->prepare("SELECT COUNT(DISTINCT product_id) FROM product_suppliers WHERE supplier_id = ? AND product_id NOT IN ({$placeholders})");
  $countSt->execute(array_merge([$supplierId], $foundProductIds));
  $nonListedDetectedCount = (int)$countSt->fetchColumn();
} else {
  $countSt = $pdo->prepare('SELECT COUNT(DISTINCT product_id) FROM product_suppliers WHERE supplier_id = ?');
  $countSt->execute([$supplierId]);
  $nonListedDetectedCount = (int)$countSt->fetchColumn();
}

$results = [];
$applyError = '';
$applySuccess = '';
$includedOkCount = 0;
$includedTotalCount = 0;
$nonListedOkCount = 0;
$nonListedTotalCount = 0;

if (is_post() && post('action') === 'ps_bulk_apply') {
  if (!can_suppliers_ps_bulk()) {
    abort403();
  }

  if ($psBaseUrl === '' || $psApiKey === '') {
    $applyError = 'El sitio no tiene URL/API Key de PrestaShop configurados.';
  } else {
    $offlineMode = post('offline_mode', 'online') === 'offline' ? 'offline' : 'online';
    $activeValue = $offlineMode === 'offline' ? 0 : 1;

    $stockMode = post('out_of_stock_mode', 'default');
    $outOfStockValue = 2;
    if ($stockMode === 'deny') {
      $outOfStockValue = 0;
    } elseif ($stockMode === 'allow') {
      $outOfStockValue = 1;
    }

    $selected = $_POST['include'] ?? [];
    if (!is_array($selected)) {
      $selected = [];
    }

    $applyToNonListed = post('apply_to_non_listed', '0') === '1';
    $respectSkuManualChanges = post('respect_sku_manual_changes', '1') === '1';
    $nonListedOfflineMode = post('non_listed_active_mode', 'en_linea') === 'fuera_linea' ? 'fuera_linea' : 'en_linea';
    $nonListedActiveValue = $nonListedOfflineMode === 'fuera_linea' ? 0 : 1;
    $nonListedStockMode = post('non_listed_outofstock_mode', 'default');
    $nonListedOutOfStockValue = 2;
    if ($nonListedStockMode === 'deny') {
      $nonListedOutOfStockValue = 0;
    } elseif ($nonListedStockMode === 'allow') {
      $nonListedOutOfStockValue = 1;
    }

    $includedProductIds = [];

    $applyProductChanges = static function (array $item, int $setActive, int $setOutOfStock, string $scopeLabel) use ($pdo, $siteId, $psBaseUrl, $psApiKey, &$results, $bulkDebugEnabled, $respectSkuManualChanges): bool {
      $productId = (int)$item['product_id'];
      $remoteProductId = null;
      $mapSt = $pdo->prepare('SELECT remote_id FROM site_product_map WHERE site_id = ? AND product_id = ? LIMIT 1');
      $mapSt->execute([$siteId, $productId]);
      $mapRow = $mapSt->fetch();
      if ($mapRow) {
        $remoteProductId = (int)$mapRow['remote_id'];
      }

      if (!$remoteProductId) {
        $testedCandidates = [];
        try {
          $remoteProductId = ps_bulk_resolve_product_id($item, $psBaseUrl, $psApiKey, $testedCandidates);
          if ($remoteProductId > 0) {
            $mapUpsertSt = $pdo->prepare("INSERT INTO site_product_map(site_id, product_id, remote_id, updated_at)
              VALUES(?,?,?,NOW())
              ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), updated_at = NOW()");
            $mapUpsertSt->execute([$siteId, $productId, (string)$remoteProductId]);
          }
        } catch (Throwable $t) {
          $results[] = [
            'scope' => $scopeLabel,
            'supplier_sku' => $item['supplier_sku'],
            'sku' => $item['sku'],
            'ps_product_id' => '',
            'active' => $setActive,
            'out_of_stock' => $setOutOfStock,
            'status' => 'ERROR',
            'error' => $t->getMessage(),
            'relink' => '',
          ];
          return false;
        }
      }

      if (!$remoteProductId) {
        $candidateText = implode(', ', $testedCandidates ?? ps_bulk_reference_candidates($item));
        $results[] = [
          'scope' => $scopeLabel,
          'supplier_sku' => $item['supplier_sku'],
          'sku' => $item['sku'],
          'ps_product_id' => '',
          'active' => $setActive,
          'out_of_stock' => $setOutOfStock,
          'status' => 'ERROR',
          'error' => 'No se pudo resolver id_product en PrestaShop. Probé: ' . $candidateText,
        ];
        return false;
      }


      $expectedSku = trim((string)($item['sku'] ?? ''));
      if ($expectedSku === '') {
        $expectedSku = trim((string)($item['supplier_sku'] ?? ''));
      }

      $relinkMessage = '';
      if ($respectSkuManualChanges) {
        try {
          $referenceActual = ps_get_product_reference_with_credentials((int)$remoteProductId, $psBaseUrl, $psApiKey);
        } catch (Throwable $t) {
          $results[] = [
            'scope' => $scopeLabel,
            'supplier_sku' => $item['supplier_sku'],
            'sku' => $item['sku'],
            'ps_product_id' => (string)$remoteProductId,
            'active' => $setActive,
            'out_of_stock' => $setOutOfStock,
            'status' => 'ERROR',
            'error' => 'No se pudo validar reference actual: ' . $t->getMessage(),
            'relink' => '',
          ];
          return false;
        }

        if ($referenceActual !== $expectedSku) {
          $testedCandidates = [];
          $newRemoteProductId = null;
          try {
            $newRemoteProductId = ps_bulk_resolve_product_id($item, $psBaseUrl, $psApiKey, $testedCandidates);
          } catch (Throwable $t) {
            $results[] = [
              'scope' => $scopeLabel,
              'supplier_sku' => $item['supplier_sku'],
              'sku' => $item['sku'],
              'ps_product_id' => (string)$remoteProductId,
              'active' => $setActive,
              'out_of_stock' => $setOutOfStock,
              'status' => 'ERROR',
              'error' => 'No se pudo resolver relink automático: ' . $t->getMessage(),
              'relink' => '',
            ];
            return false;
          }

          if ($newRemoteProductId && (int)$newRemoteProductId !== (int)$remoteProductId) {
            $oldRemoteProductId = (int)$remoteProductId;
            $remoteProductId = (int)$newRemoteProductId;
            $relinkMessage = 'RELINK ' . $oldRemoteProductId . ' -> ' . $remoteProductId;

            $mapUpsertSt = $pdo->prepare("INSERT INTO site_product_map(site_id, product_id, remote_id, updated_at)
              VALUES(?,?,?,NOW())
              ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), updated_at = NOW()");
            $mapUpsertSt->execute([$siteId, $productId, (string)$remoteProductId]);
          } else {
            $candidateText = implode(', ', $testedCandidates ?: ps_bulk_reference_candidates($item));
            $results[] = [
              'scope' => $scopeLabel,
              'supplier_sku' => $item['supplier_sku'],
              'sku' => $item['sku'],
              'ps_product_id' => (string)$remoteProductId,
              'active' => $setActive,
              'out_of_stock' => $setOutOfStock,
              'status' => 'SKIPPED',
              'error' => 'Referencia en PrestaShop fue modificada (actual=' . $referenceActual . ', esperado=' . $expectedSku . '). No se encontró relink automático. Probé: ' . $candidateText,
              'relink' => '',
            ];
            return false;
          }
        }
      }

      try {
        $activeUpdateDebug = ps_update_product_active_with_credentials($remoteProductId, $setActive, $psBaseUrl, $psApiKey);
        ps_update_product_out_of_stock_by_product_with_credentials($remoteProductId, $setOutOfStock, $psBaseUrl, $psApiKey);

        $resultRow = [
          'scope' => $scopeLabel,
          'supplier_sku' => $item['supplier_sku'],
          'sku' => $item['sku'],
          'ps_product_id' => (string)$remoteProductId,
          'active' => $setActive,
          'out_of_stock' => $setOutOfStock,
          'status' => 'OK',
          'error' => '',
          'relink' => $relinkMessage,
          'request_url' => (string)($activeUpdateDebug['url'] ?? ''),
          'request_method' => (string)($activeUpdateDebug['method'] ?? 'PUT'),
          'status_code' => (string)($activeUpdateDebug['status_code'] ?? ''),
          'response_body_xml' => '',
        ];
        if ($bulkDebugEnabled) {
          $resultRow['reference_before'] = (string)($activeUpdateDebug['reference_before'] ?? '');
          $resultRow['reference_after'] = (string)($activeUpdateDebug['reference_after'] ?? '');
        }
        $results[] = $resultRow;
        return true;
      } catch (Throwable $t) {
        $debug = [];
        if ($t instanceof PsRequestException) {
          $debug = $t->details;
        }
        $results[] = [
          'scope' => $scopeLabel,
          'supplier_sku' => $item['supplier_sku'],
          'sku' => $item['sku'],
          'ps_product_id' => (string)$remoteProductId,
          'active' => $setActive,
          'out_of_stock' => $setOutOfStock,
          'status' => 'ERROR',
          'error' => $t->getMessage(),
          'relink' => $relinkMessage,
          'request_url' => (string)($debug['url'] ?? ''),
          'request_method' => (string)($debug['method'] ?? 'PUT'),
          'status_code' => (string)($debug['status_code'] ?? ''),
          'response_body_xml' => (string)($debug['response_body_xml'] ?? ''),
        ];
        return false;
      }
    };

    foreach ($found as $item) {
      $productId = (int)$item['product_id'];
      if (!isset($selected[$productId])) {
        continue;
      }

      $includedProductIds[] = $productId;
      $includedTotalCount++;
      if ($applyProductChanges($item, $activeValue, $outOfStockValue, 'incluido CSV')) {
        $includedOkCount++;
      }
    }

    if ($applyToNonListed) {
      $nonListedItems = [];
      if (count($includedProductIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($includedProductIds), '?'));
        $nonListedSt = $pdo->prepare("SELECT p.id, p.sku, p.name, ps.supplier_sku
          FROM product_suppliers ps
          INNER JOIN products p ON p.id = ps.product_id
          WHERE ps.supplier_id = ? AND ps.product_id NOT IN ({$placeholders})
          GROUP BY p.id, p.sku, p.name, ps.supplier_sku");
        $nonListedSt->execute(array_merge([$supplierId], $includedProductIds));
      } else {
        $nonListedSt = $pdo->prepare('SELECT p.id, p.sku, p.name, ps.supplier_sku
          FROM product_suppliers ps
          INNER JOIN products p ON p.id = ps.product_id
          WHERE ps.supplier_id = ?
          GROUP BY p.id, p.sku, p.name, ps.supplier_sku');
        $nonListedSt->execute([$supplierId]);
      }

      while ($row = $nonListedSt->fetch()) {
        $nonListedItems[] = [
          'product_id' => (int)$row['id'],
          'supplier_sku' => (string)$row['supplier_sku'],
          'sku' => (string)$row['sku'],
          'name' => (string)$row['name'],
        ];
      }

      $nonListedTotalCount = count($nonListedItems);
      foreach ($nonListedItems as $item) {
        if ($applyProductChanges($item, $nonListedActiveValue, $nonListedOutOfStockValue, 'no incluido')) {
          $nonListedOkCount++;
        }
      }
    }

    if ($includedTotalCount > 0 || $nonListedTotalCount > 0) {
      $applySuccess = "Proceso finalizado. Incluidos actualizados: {$includedOkCount} / {$includedTotalCount}. No incluidos actualizados: {$nonListedOkCount} / {$nonListedTotalCount}.";
    } else {
      $applyError = 'No hay productos seleccionados para aplicar.';
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="<?= e(app_body_class()) ?>">
<?php require __DIR__ . '/partials/header.php'; ?>
<main class="page">
  <div class="container stack">
    <div class="page-header">
      <div>
        <h2 class="page-title">PrestaShop · acciones masivas por proveedor</h2>
        <p class="muted" style="margin:0">Proveedor: <strong><?= e((string)$supplier['name']) ?></strong> · Sitio: <strong><?= e((string)$site['name']) ?></strong></p>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers_edit.php?id=<?= (int)$supplierId ?>">Volver</a>
      </div>
    </div>

    <?php if ($applyError !== ''): ?><div class="alert alert-danger"><?= e($applyError) ?></div><?php endif; ?>
    <?php if ($applySuccess !== ''): ?><div class="alert alert-success"><?= e($applySuccess) ?></div><?php endif; ?>

    <div class="card stack">
      <h3 style="margin:0">Resumen CSV</h3>
      <div>Encontrados: <strong><?= count($found) ?></strong></div>
      <div>No encontrados: <strong><?= count($notFound) ?></strong></div>
      <?php if (count($notFound) > 0): ?>
        <details>
          <summary>Ver SKUs no encontrados</summary>
          <div class="muted" style="margin-top:var(--space-2)"><?= e(implode(', ', array_slice($notFound, 0, 300))) ?></div>
        </details>
      <?php endif; ?>
    </div>

    <div class="card stack">
      <form method="post" class="stack">
        <input type="hidden" name="action" value="ps_bulk_apply">

        <div class="form-field">
          <span class="form-label">Fuera de línea</span>
          <select class="form-control" name="offline_mode">
            <option value="online">En línea</option>
            <option value="offline">Fuera de línea</option>
          </select>
        </div>

        <div class="form-field">
          <span class="form-label">Cuando no haya existencias</span>
          <label><input type="radio" name="out_of_stock_mode" value="deny"> Denegar pedidos</label><br>
          <label><input type="radio" name="out_of_stock_mode" value="allow"> Permitir pedidos</label><br>
          <label><input type="radio" name="out_of_stock_mode" value="default" checked> Usar comportamiento predeterminado (Denegar pedidos)</label>
        </div>


        <div class="form-field">
          <label><input type="checkbox" name="respect_sku_manual_changes" value="1" checked> Respetar cambios manuales de SKU en PrestaShop</label>
          <div class="muted" style="margin-top:var(--space-2)">Si la referencia actual del producto no coincide con el SKU esperado en TS Work, se marcará como SKIPPED y no se aplicarán cambios.</div>
        </div>

        <div class="form-field">
          <span class="form-label">Productos del proveedor no incluidos en el CSV</span>
          <label><input type="radio" name="apply_to_non_listed" value="0" checked> No hacer nada</label><br>
          <label><input type="radio" name="apply_to_non_listed" value="1"> Hacer cambios</label>
          <div class="muted" style="margin-top:var(--space-2)">No incluidos detectados: <strong><?= (int)$nonListedDetectedCount ?></strong></div>
        </div>

        <div id="non-listed-options" class="card stack" style="display:none">
          <h4 style="margin:0">Configuración para NO incluidos</h4>
          <div class="form-field">
            <span class="form-label">Fuera de línea (para NO incluidos)</span>
            <select class="form-control" name="non_listed_active_mode">
              <option value="en_linea">En línea</option>
              <option value="fuera_linea">Fuera de línea</option>
            </select>
          </div>

          <div class="form-field">
            <span class="form-label">Cuando no haya existencias (para NO incluidos)</span>
            <label><input type="radio" name="non_listed_outofstock_mode" value="deny"> Denegar pedidos</label><br>
            <label><input type="radio" name="non_listed_outofstock_mode" value="allow"> Permitir pedidos</label><br>
            <label><input type="radio" name="non_listed_outofstock_mode" value="default" checked> Usar comportamiento predeterminado</label>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Incluir</th>
                <th>SKU proveedor</th>
                <th>SKU TSWork</th>
                <th>Nombre</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($found as $item): ?>
              <tr>
                <td><input type="checkbox" name="include[<?= (int)$item['product_id'] ?>]" value="1" checked></td>
                <td><?= e($item['supplier_sku']) ?></td>
                <td><?= e($item['sku']) ?></td>
                <td><?= e($item['name']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="inline-actions">
          <button class="btn" type="submit">Aplicar cambios en PrestaShop</button>
        </div>
      </form>
    </div>

    <?php if (count($results) > 0): ?>
      <div class="card stack">
        <h3 style="margin:0">Resultados</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>SKU proveedor</th>
                <th>SKU TSWork</th>
                <th>Origen</th>
                <th>PS product id</th>
                <th>active set</th>
                <th>out_of_stock set</th>
                <th>Estado</th>
                <th>Relink</th>
                <th>Error</th>
                <th>Request URL</th>
                <th>Método</th>
                <th>HTTP</th>
                <?php if ($bulkDebugEnabled): ?>
                  <th>reference_before</th>
                  <th>reference_after</th>
                <?php endif; ?>
                <th>Response XML (recortado)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $row): ?>
              <tr>
                <td><?= e($row['supplier_sku']) ?></td>
                <td><?= e($row['sku']) ?></td>
                <td><?= e((string)($row['scope'] ?? 'incluido CSV')) ?></td>
                <td><?= e((string)$row['ps_product_id']) ?></td>
                <td><?= (int)$row['active'] ?></td>
                <td><?= (int)$row['out_of_stock'] ?></td>
                <td><?= e($row['status']) ?></td>
                <td><?= e((string)($row['relink'] ?? '')) ?></td>
                <td><?= e($row['error']) ?></td>
                <td><?= e((string)($row['request_url'] ?? '')) ?></td>
                <td><?= e((string)($row['request_method'] ?? '')) ?></td>
                <td><?= e((string)($row['status_code'] ?? '')) ?></td>
                <?php if ($bulkDebugEnabled): ?>
                  <td><?= e((string)($row['reference_before'] ?? '')) ?></td>
                  <td><?= e((string)($row['reference_after'] ?? '')) ?></td>
                <?php endif; ?>
                <td><pre style="margin:0;white-space:pre-wrap"><?= e((string)($row['response_body_xml'] ?? '')) ?></pre></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
<script>
  (function () {
    const options = document.getElementById('non-listed-options');
    const radios = document.querySelectorAll('input[name="apply_to_non_listed"]');
    if (!options || radios.length === 0) {
      return;
    }

    function toggleOptions() {
      const selected = document.querySelector('input[name="apply_to_non_listed"]:checked');
      options.style.display = selected && selected.value === '1' ? 'block' : 'none';
    }

    radios.forEach((radio) => {
      radio.addEventListener('change', toggleOptions);
    });

    toggleOptions();
  })();
</script>
</body>
</html>
