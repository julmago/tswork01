<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

final class PsRequestException extends RuntimeException {
  /** @var array<string,string|int> */
  public array $details;

  /** @param array<string,string|int> $details */
  public function __construct(string $message, array $details = [], int $code = 0, ?Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
    $this->details = $details;
  }
}

function ps_base_url(): string {
  $url = trim(setting_get('prestashop_url', ''));
  // Normalizar: sin slash final
  $url = rtrim($url, "/");
  return $url;
}

function ps_base_url_api(?string $baseUrl = null): string {
  $base = $baseUrl !== null ? trim($baseUrl) : ps_base_url();
  $base = rtrim($base, '/');
  if ($base === '') {
    return '';
  }
  if (preg_match('~/api$~i', $base) === 1) {
    return $base;
  }
  return $base . '/api';
}

function ps_api_key(): string {
  return trim(setting_get('prestashop_api_key', ''));
}

function ps_mode(): string {
  $m = trim(setting_get('prestashop_mode', 'replace'));
  return in_array($m, ['replace','add'], true) ? $m : 'replace';
}

function ps_build_url(string $path): string {
  $base = ps_base_url_api();
  if ($base === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL base).");
  }
  $normalized = $path;
  if (!str_starts_with($normalized, '/')) {
    $normalized = '/' . $normalized;
  }
  if (str_starts_with($normalized, '/api/')) {
    $normalized = substr($normalized, 4);
  } elseif ($normalized === '/api') {
    $normalized = '';
  }
  return $base . $normalized;
}

function ps_has_header(array $headers, string $needle): bool {
  foreach ($headers as $header) {
    if (stripos($header, $needle . ':') === 0) {
      return true;
    }
  }
  return false;
}

function ps_request(string $method, string $path, ?string $body = null, array $headers = []): array {
  $base = ps_base_url();
  $key  = ps_api_key();
  if ($base === '' || $key === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL / API Key).");
  }

  return ps_request_with_credentials($method, $path, $base, $key, $body, $headers);
}

function ps_request_with_credentials(string $method, string $path, string $base, string $key, ?string $body = null, array $headers = []): array {
  $base = ps_base_url_api($base);
  $key = trim($key);
  if ($base === '' || $key === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL / API Key).");
  }

  $normalized = $path;
  if (!str_starts_with($normalized, '/')) {
    $normalized = '/' . $normalized;
  }
  if (str_starts_with($normalized, '/api/')) {
    $normalized = substr($normalized, 4);
  } elseif ($normalized === '/api') {
    $normalized = '';
  }
  $url = $base . $normalized;

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_USERPWD, $key . ":"); // basic auth, password vacía
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  if (!ps_has_header($headers, 'Accept')) {
    $headers[] = 'Accept: application/xml';
  }

  if ($body !== null) {
    if (!ps_has_header($headers, 'Content-Type')) {
      $headers[] = 'Content-Type: application/xml; charset=utf-8';
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $responseHeaders = [];
  curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function($curl, $headerLine) use (&$responseHeaders) {
    $trimmed = trim($headerLine);
    if ($trimmed !== '') {
      $responseHeaders[] = $trimmed;
    }
    return strlen($headerLine);
  });

  error_log("[PrestaShop] Request: {$method} {$url}");

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException("Error cURL: " . $err);
  }

  $snippet = substr($resp, 0, 1000);
  error_log("[PrestaShop] Response: HTTP {$code} | Content-Type: " . ($contentType ?: 'n/a'));
  error_log("[PrestaShop] Body (first 1000 chars): " . $snippet);

  return [
    'code' => $code,
    'body' => $resp,
    'content_type' => $contentType,
    'url' => $url,
    'headers' => $responseHeaders,
  ];
}

function ps_truncate_text(string $text, int $max = 1500): string {
  if ($max < 1) {
    return '';
  }
  if (strlen($text) <= $max) {
    return $text;
  }
  return substr($text, 0, $max) . '… [truncado]';
}

function ps_xml_load(string $xml): SimpleXMLElement {
  libxml_use_internal_errors(true);
  $sx = simplexml_load_string($xml);
  if (!$sx) {
    $errs = libxml_get_errors();
    libxml_clear_errors();
    throw new RuntimeException("Respuesta XML inválida desde PrestaShop.");
  }
  return $sx;
}

function ps_extract_product_id(SimpleXMLElement $product): int {
  $id_text = trim((string)$product->id);
  if ($id_text !== '') {
    return (int)$id_text;
  }

  $attr_id = trim((string)$product->attributes()->id);
  if ($attr_id !== '') {
    return (int)$attr_id;
  }

  return 0;
}

function ps_find_first_product_id(SimpleXMLElement $sx): ?int {
  if (!isset($sx->products->product)) {
    return null;
  }

  $products = $sx->products->product;

  if (is_array($products)) {
    foreach ($products as $product) {
      $id = ps_extract_product_id($product);
      if ($id > 0) {
        return $id;
      }
    }
    return null;
  }

  foreach ($products as $product) {
    $id = ps_extract_product_id($product);
    if ($id > 0) {
      return $id;
    }
  }

  return null;
}

function ps_find_by_reference_all(string $sku, ?string $baseUrl = null, ?string $apiKey = null): array {
  $sku = trim($sku);
  if ($sku === '') return [];
  $base = $baseUrl !== null ? rtrim(trim($baseUrl), '/') : ps_base_url();
  $key = $apiKey !== null ? trim($apiKey) : ps_api_key();
  if ($base === '' || $key === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL / API Key).");
  }

  $encoded_sku = rawurlencode($sku);
  $results = [];

  $q = "/api/combinations?display=[id,id_product,reference]&filter[reference]=[" . $encoded_sku . "]";
  error_log("[PrestaShop] Lookup combinations by reference URL: " . $base . $q);
  $r = ps_request_with_credentials("GET", $q, $base, $key);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sx = ps_xml_load($r['body']);
    if (isset($sx->combinations->combination)) {
      foreach ($sx->combinations->combination as $comb) {
        $id_attr = (int)$comb->attributes()->id;
        $id_prod = (int)trim((string)$comb->id_product);
        if ($id_attr > 0 && $id_prod > 0) {
          $results[] = ['type' => 'combination', 'id_product' => $id_prod, 'id_product_attribute' => $id_attr, 'sku' => $sku];
        }
      }
    }
  }

  $q = "/api/products?display=[id,reference]&filter[reference]=[" . $encoded_sku . "]";
  error_log("[PrestaShop] Lookup products by reference URL: " . $base . $q);
  $r = ps_request_with_credentials("GET", $q, $base, $key);
  $snippet = substr($r['body'], 0, 500);
  error_log("[PrestaShop] products HTTP {$r['code']} | Body (first 500 chars): " . $snippet);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sx = ps_xml_load($r['body']);
    if (isset($sx->products->product)) {
      foreach ($sx->products->product as $product) {
        $id_prod = ps_extract_product_id($product);
        if ($id_prod > 0) {
          $results[] = ['type' => 'product', 'id_product' => $id_prod, 'id_product_attribute' => 0, 'sku' => $sku];
        }
      }
    }
  }

  return $results;
}

/**
 * Busca un producto o combinación por SKU (reference).
 * Retorna:
 *  - ['type'=>'combination','id_product'=>int,'id_product_attribute'=>int]
 *  - ['type'=>'product','id_product'=>int,'id_product_attribute'=>0]
 */
function ps_find_by_reference(string $sku): ?array {
  return ps_find_by_reference_with_credentials($sku, ps_base_url(), ps_api_key());
}

function ps_find_by_reference_with_credentials(string $sku, string $baseUrl, string $apiKey): ?array {
  $results = ps_find_by_reference_all($sku, $baseUrl, $apiKey);
  if (!$results) {
    return null;
  }
  $first = $results[0];
  return [
    'type' => $first['type'],
    'id_product' => (int)$first['id_product'],
    'id_product_attribute' => (int)$first['id_product_attribute'],
  ];
}

function ps_find_stock_available_id(int $id_product, int $id_product_attribute): ?int {
  return ps_find_stock_available_id_with_credentials($id_product, $id_product_attribute, ps_base_url(), ps_api_key());
}

function ps_find_stock_available_id_with_credentials(int $id_product, int $id_product_attribute, string $baseUrl, string $apiKey): ?int {
  $filter_attr = 0;
  $query = http_build_query([
    'display' => '[id,id_product,id_product_attribute,quantity]',
    'filter[id_product]' => '[' . $id_product . ']',
    'filter[id_product_attribute]' => '[' . $filter_attr . ']',
  ], '', '&', PHP_QUERY_RFC3986);
  $q = "/api/stock_availables?" . $query;
  $base = rtrim(trim($baseUrl), '/');
  error_log("[PrestaShop] Lookup stock_availables URL: " . $base . (str_starts_with($q, '/api') ? $q : '/api' . $q));
  $r = ps_request_with_credentials("GET", $q, $baseUrl, $apiKey);
  $snippet = substr($r['body'], 0, 500);
  error_log("[PrestaShop] stock_availables HTTP {$r['code']} | Body (first 500 chars): " . $snippet);
  if (!($r['code'] >= 200 && $r['code'] < 300)) {
    throw new RuntimeException("No se pudo consultar stock_availables (HTTP {$r['code']}).");
  }

  $sx = ps_xml_load($r['body']);
  if (!isset($sx->stock_availables)) {
    throw new RuntimeException("Respuesta XML inesperada al buscar stock_available.");
  }

  if (isset($sx->stock_availables->stock_available)) {
    foreach ($sx->stock_availables->stock_available as $sa) {
      $attr_id = (int)trim((string)$sa->id_product_attribute);
      if ($attr_id !== 0) {
        continue;
      }
      $id = (int)trim((string)$sa->id);
      if ($id > 0) {
        return $id;
      }
    }
  }

  return null;
}

function ps_get_stock_available(int $id_stock_available): array {
  return ps_get_stock_available_with_credentials($id_stock_available, ps_base_url(), ps_api_key());
}

function ps_get_stock_available_with_credentials(int $id_stock_available, string $baseUrl, string $apiKey): array {
  $r = ps_request_with_credentials("GET", "/api/stock_availables/" . $id_stock_available, $baseUrl, $apiKey);
  if (!($r['code'] >= 200 && $r['code'] < 300)) {
    throw new RuntimeException("No se pudo leer stock_available #{$id_stock_available} (HTTP {$r['code']}).");
  }
  $sx = ps_xml_load($r['body']);
  // Estructura: <prestashop><stock_available>...</stock_available></prestashop>
  $qty = (int)$sx->stock_available->quantity;
  return ['xml' => $r['body'], 'qty' => $qty];
}

function ps_update_stock_available_quantity(int $id_stock_available, int $new_qty): void {
  ps_update_stock_available_quantity_with_credentials($id_stock_available, $new_qty, ps_base_url(), ps_api_key());
}

function ps_update_stock_available_quantity_with_credentials(int $id_stock_available, int $new_qty, string $baseUrl, string $apiKey): void {
  $current = ps_get_stock_available_with_credentials($id_stock_available, $baseUrl, $apiKey);
  $sx = ps_xml_load($current['xml']);
  $sx->stock_available->quantity = (string)$new_qty;

  // Necesitamos re-emitir XML
  // SimpleXML no conserva exactamente headers, pero PrestaShop acepta el XML.
  $xml = $sx->asXML();
  if ($xml === false) {
    throw new RuntimeException("No se pudo generar XML para actualizar stock.");
  }

  $r = ps_request_with_credentials("PUT", "/api/stock_availables/" . $id_stock_available, $baseUrl, $apiKey, $xml);
  if (!in_array((int)$r['code'], [200, 201], true)) {
    $responseHeadersText = isset($r['headers']) && is_array($r['headers'])
      ? implode("\n", $r['headers'])
      : '';
    error_log("[PrestaShop] PUT stock_available falló | URL final: " . ($r['url'] ?? 'n/a') . " | id_stock_available: {$id_stock_available} | HTTP " . (int)$r['code']);
    error_log("[PrestaShop] PUT request XML body:\n" . $xml);
    error_log("[PrestaShop] PUT response headers:\n" . ($responseHeadersText !== '' ? $responseHeadersText : '(sin headers)'));
    error_log("[PrestaShop] PUT response body completo:\n" . (string)($r['body'] ?? ''));
    throw new RuntimeException("Falló actualización stock_available #{$id_stock_available} (HTTP {$r['code']}).");
  }
}

function ps_extract_created_stock_available_id(SimpleXMLElement $sx): ?int {
  if (isset($sx->stock_available->id)) {
    $id = (int)trim((string)$sx->stock_available->id);
    return $id > 0 ? $id : null;
  }
  if (isset($sx->stock_available) && $sx->stock_available->attributes() && isset($sx->stock_available->attributes()->id)) {
    $id = (int)$sx->stock_available->attributes()->id;
    return $id > 0 ? $id : null;
  }
  if (isset($sx->stock_available)) {
    $id_text = trim((string)$sx->stock_available);
    $id = (int)$id_text;
    return $id > 0 ? $id : null;
  }
  return null;
}

function ps_create_stock_available(int $id_product, int $id_product_attribute, int $quantity): int {
  return ps_create_stock_available_with_credentials($id_product, $id_product_attribute, $quantity, ps_base_url(), ps_api_key());
}

function ps_create_stock_available_with_credentials(int $id_product, int $id_product_attribute, int $quantity, string $baseUrl, string $apiKey): int {
  $qty = max(0, $quantity);
  $sx = new SimpleXMLElement('<prestashop></prestashop>');
  $sa = $sx->addChild('stock_available');
  $sa->addChild('id_product', (string)$id_product);
  $sa->addChild('id_product_attribute', (string)$id_product_attribute);
  $sa->addChild('quantity', (string)$qty);
  $sa->addChild('depends_on_stock', '0');
  $sa->addChild('out_of_stock', '2');

  $xml = $sx->asXML();
  if ($xml === false) {
    throw new RuntimeException("No se pudo generar XML para crear stock.");
  }

  $r = ps_request_with_credentials("POST", "/api/stock_availables", $baseUrl, $apiKey, $xml);
  if (!($r['code'] >= 200 && $r['code'] < 300)) {
    throw new RuntimeException("Falló creación de stock_available (HTTP {$r['code']}).");
  }

  $resp = ps_xml_load($r['body']);
  $id = ps_extract_created_stock_available_id($resp);
  if ($id === null) {
    throw new RuntimeException("No se obtuvo ID de stock_available creado.");
  }
  return $id;
}

function ps_get_product_with_credentials(int $idProduct, string $baseUrl, string $apiKey): SimpleXMLElement {
  $r = ps_request_with_credentials('GET', '/api/products/' . $idProduct, $baseUrl, $apiKey);
  if ($r['code'] < 200 || $r['code'] >= 300) {
    throw new RuntimeException("No se pudo leer product #{$idProduct} (HTTP {$r['code']}).");
  }
  return ps_xml_load($r['body']);
}

function ps_extract_product_required_parameter_name(string $responseBody): ?string {
  if (preg_match('/parameter\s+([a-z0-9_]+)\s+required/i', $responseBody, $m) === 1) {
    return strtolower(trim((string)$m[1]));
  }
  return null;
}

function ps_extract_non_writable_product_fields(string $responseBody): array {
  $patterns = [
    '/parameter\s+([a-z0-9_]+)\s+is\s+not\s+writable/i',
    '/parameter\s+([a-z0-9_]+)\s+not\s+writable/i',
    '/parameter\s+"([a-z0-9_]+)".*is\s+not\s+writable/i',
    '/parameter\s+"([a-z0-9_]+)".*not\s+writable/i',
    '/property\s+[a-z0-9_\\\\]+->([a-z0-9_]+)\s+is\s+not\s+writable/i',
    '/field\s+"([a-z0-9_]+)"\s+is\s+not\s+writable/i',
    '/"([a-z0-9_]+)"\s+is\s+not\s+writable/i',
  ];
  $found = [];
  foreach ($patterns as $pattern) {
    if (preg_match_all($pattern, $responseBody, $matches) > 0) {
      foreach (($matches[1] ?? []) as $field) {
        $f = strtolower(trim((string)$field));
        if ($f !== '') {
          $found[$f] = true;
        }
      }
    }
  }
  return array_keys($found);
}

function ps_get_product_field_value(SimpleXMLElement $productNode, string $field): ?string {
  if (!isset($productNode->{$field})) {
    return null;
  }
  return (string)$productNode->{$field};
}

function ps_get_product_field_canonical(SimpleXMLElement $productNode, string $field): string {
  if (!isset($productNode->{$field})) {
    return '';
  }
  $domNode = dom_import_simplexml($productNode->{$field});
  if (!$domNode) {
    return trim((string)$productNode->{$field});
  }
  $canonical = $domNode->C14N();
  if ($canonical === false) {
    return trim((string)$productNode->{$field});
  }
  return trim($canonical);
}

function ps_update_product_active_with_credentials(int $idProduct, int $active, string $baseUrl, string $apiKey): array {
  $normalizedActive = $active > 0 ? '1' : '0';

  $protectedFields = ['name', 'description', 'price', 'associations'];
  $removedFields = ['manufacturer_name' => true, 'quantity' => true, 'position_in_category' => true];
  $attempts = 0;
  $lastDetails = [];

  while ($attempts < 4) {
    $attempts++;
    $productXml = ps_get_product_with_credentials($idProduct, $baseUrl, $apiKey);
    $productNode = isset($productXml->product) ? $productXml->product : $productXml;
    $productNode->active = $normalizedActive;

    foreach ($removedFields as $field => $_true) {
      if (isset($productNode->{$field})) {
        unset($productNode->{$field});
      }
    }

    $payloadXml = $productXml->asXML();
    if ($payloadXml === false) {
      throw new RuntimeException("No se pudo generar XML para actualizar product.active #{$idProduct}.");
    }

    $putResponse = ps_request_with_credentials(
      'PUT',
      '/api/products/' . $idProduct,
      $baseUrl,
      $apiKey,
      $payloadXml,
      [
        'Content-Type: application/xml',
        'Accept: application/xml',
      ]
    );

    $details = [
      'url' => (string)($putResponse['url'] ?? ''),
      'method' => 'PUT',
      'status_code' => (int)$putResponse['code'],
      'request_payload_xml' => ps_truncate_text($payloadXml),
      'response_body_xml' => ps_truncate_text((string)($putResponse['body'] ?? '')),
    ];
    $lastDetails = $details;

    if (in_array((int)$putResponse['code'], [200, 201], true)) {
      return $details;
    }

    $body = (string)($putResponse['body'] ?? '');
    if (in_array((int)$putResponse['code'], [401, 403], true)
      || stripos($body, 'permission') !== false
      || stripos($body, 'forbidden') !== false) {
      throw new PsRequestException('Permisos insuficientes: habilitar PUT products en PrestaShop Webservice.', $details);
    }

    $rejectFields = ps_extract_non_writable_product_fields($body);
    if (!$rejectFields) {
      throw new PsRequestException("Falló actualización de product.active para #{$idProduct} (HTTP " . (int)$putResponse['code'] . ').', $details);
    }

    $newlyAccepted = false;
    foreach ($rejectFields as $field) {
      if (in_array($field, $protectedFields, true)) {
        throw new PsRequestException("PrestaShop rechazó campo protegido '{$field}' al actualizar product.active #{$idProduct}.", $details);
      }
      if (!isset($removedFields[$field])) {
        $removedFields[$field] = true;
        $newlyAccepted = true;
      }
    }

    if (!$newlyAccepted) {
      throw new PsRequestException("Falló actualización de product.active para #{$idProduct} (HTTP " . (int)$putResponse['code'] . ').', $details);
    }
  }

  throw new PsRequestException("Falló actualización de product.active para #{$idProduct} luego de múltiples intentos.", $lastDetails);
}

function ps_update_product_out_of_stock_by_product_with_credentials(int $idProduct, int $outOfStock, string $baseUrl, string $apiKey): void {
  $idStock = ps_find_stock_available_id_with_credentials($idProduct, 0, $baseUrl, $apiKey);
  if (!$idStock) {
    $idStock = ps_create_stock_available_with_credentials($idProduct, 0, 0, $baseUrl, $apiKey);
  }

  $normalized = in_array($outOfStock, [0, 1, 2], true) ? $outOfStock : 2;
  $get = ps_request_with_credentials('GET', '/api/stock_availables/' . $idStock, $baseUrl, $apiKey);
  if (!in_array((int)$get['code'], [200, 201], true)) {
    throw new RuntimeException("No se pudo leer stock_available #{$idStock} (HTTP {$get['code']}).");
  }

  $stockXml = ps_xml_load((string)$get['body']);
  $stockNode = isset($stockXml->stock_available) ? $stockXml->stock_available : $stockXml;
  $stockNode->out_of_stock = (string)$normalized;
  $xml = $stockXml->asXML();
  if ($xml === false) {
    throw new RuntimeException("No se pudo generar XML para stock_available #{$idStock}.");
  }

  $put = ps_request_with_credentials('PUT', '/api/stock_availables/' . $idStock, $baseUrl, $apiKey, $xml, [
    'Content-Type: application/xml',
    'Accept: application/xml',
  ]);
  if (!in_array((int)$put['code'], [200, 201], true)) {
    throw new RuntimeException("Falló actualización out_of_stock para stock_available #{$idStock} (HTTP {$put['code']}).");
  }
}
