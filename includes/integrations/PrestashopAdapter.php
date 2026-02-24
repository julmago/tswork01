<?php

declare(strict_types=1);

class PrestashopAdapter {
  public static function updateStock(string $baseUrl, string $apiKey, string $remoteId, ?string $remoteVariantId, int $qty): void {
    $productId = (int)$remoteId;
    $attrId = (int)($remoteVariantId ?? '0');
    if ($productId <= 0) {
      throw new InvalidArgumentException('remote_id inválido para PrestaShop.');
    }

    $stockAvailableId = self::findStockAvailableId($baseUrl, $apiKey, $productId, $attrId);
    if ($stockAvailableId <= 0) {
      throw new RuntimeException('No existe stock_available para ese mapping en PrestaShop.');
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<prestashop><stock_available>'
      . '<id>' . $stockAvailableId . '</id>'
      . '<id_product>' . $productId . '</id_product>'
      . '<id_product_attribute>' . max(0, $attrId) . '</id_product_attribute>'
      . '<quantity>' . $qty . '</quantity>'
      . '</stock_available></prestashop>';

    $response = self::request($baseUrl, $apiKey, 'PUT', '/api/stock_availables/' . $stockAvailableId, $xml);
    if ($response['code'] < 200 || $response['code'] >= 300) {
      throw new RuntimeException('Error al actualizar stock en PrestaShop (HTTP ' . $response['code'] . ').');
    }
  }

  private static function findStockAvailableId(string $baseUrl, string $apiKey, int $productId, int $attrId): int {
    $query = http_build_query([
      'display' => '[id,id_product,id_product_attribute]',
      'filter[id_product]' => '[' . $productId . ']',
      'filter[id_product_attribute]' => '[' . max(0, $attrId) . ']',
    ], '', '&', PHP_QUERY_RFC3986);

    $response = self::request($baseUrl, $apiKey, 'GET', '/api/stock_availables?' . $query, null);
    if ($response['code'] < 200 || $response['code'] >= 300) {
      throw new RuntimeException('Error consultando stock_availables (HTTP ' . $response['code'] . ').');
    }

    $sx = self::xmlLoad($response['body']);
    if (!isset($sx->stock_availables->stock_available)) {
      return 0;
    }

    foreach ($sx->stock_availables->stock_available as $sa) {
      $id = (int)($sa->id ?? 0);
      if ($id > 0) {
        return $id;
      }
    }

    return 0;
  }

  private static function request(string $baseUrl, string $apiKey, string $method, string $path, ?string $body): array {
    $url = rtrim($baseUrl, '/') . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_USERPWD, trim($apiKey) . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $headers = ['Accept: application/xml'];
    if ($body !== null) {
      $headers[] = 'Content-Type: application/xml; charset=utf-8';
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
      throw new RuntimeException('Error cURL PrestaShop: ' . $err);
    }

    return ['code' => $code, 'body' => (string)$resp];
  }

  private static function xmlLoad(string $xml): SimpleXMLElement {
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml);
    if (!$sx) {
      throw new RuntimeException('XML inválido de PrestaShop.');
    }
    return $sx;
  }
}
