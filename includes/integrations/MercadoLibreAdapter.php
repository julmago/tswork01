<?php

declare(strict_types=1);

class MercadoLibreAdapter {
  public static function updateStock(string $accessToken, string $itemId, ?string $variationId, int $qty): void {
    $response = self::updateStockWithResponse($accessToken, $itemId, $variationId, $qty);
    if ($response['code'] < 200 || $response['code'] >= 300) {
      throw new RuntimeException('Error al actualizar stock en MercadoLibre (HTTP ' . $response['code'] . '). ' . mb_substr((string)$response['body'], 0, 500));
    }
  }

  public static function updateStockWithResponse(string $accessToken, string $itemId, ?string $variationId, int $qty): array {
    $trimmedVariationId = $variationId !== null ? trim($variationId) : '';
    if ($trimmedVariationId !== '') {
      $response = self::request(
        'PUT',
        'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '/variations/' . rawurlencode($trimmedVariationId),
        $accessToken,
        ['available_quantity' => $qty]
      );
      return $response;
    }

    $response = self::request('PUT', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken, ['available_quantity' => $qty]);
    return $response;
  }

  public static function getUserMe(string $accessToken): array {
    return self::request('GET', 'https://api.mercadolibre.com/users/me', $accessToken, null);
  }

  public static function getItem(string $accessToken, string $itemId): array {
    return self::request('GET', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken, null);
  }

  public static function fetchRecentOrders(string $accessToken, ?string $sinceIso): array {
    $url = 'https://api.mercadolibre.com/orders/search?seller=me&sort=date_desc';
    if ($sinceIso !== null && trim($sinceIso) !== '') {
      $url .= '&order.date_created.from=' . rawurlencode($sinceIso);
    }

    $response = self::request('GET', $url, $accessToken, null);
    if ($response['code'] < 200 || $response['code'] >= 300) {
      throw new RuntimeException('No se pudieron obtener órdenes de MercadoLibre (HTTP ' . $response['code'] . ').');
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
      return [];
    }

    return $data['results'];
  }

  private static function request(string $method, string $url, string $accessToken, ?array $jsonBody): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $headers = ['Authorization: Bearer ' . trim($accessToken), 'Accept: application/json'];
    if ($jsonBody !== null) {
      $headers[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
      throw new RuntimeException('Error cURL MercadoLibre: ' . $err);
    }

    return ['code' => $code, 'body' => (string)$resp];
  }
}
