# Mini módulo PrestaShop: TS Work Stock Webhook

Ruta del módulo a instalar en PrestaShop:

- `integrations/prestashop_stock_webhook/tsworkstockwebhook/`

## Qué hace

- Hook principal: `actionUpdateQuantity`.
- Hook opcional: `actionValidateOrder`.
- Obtiene SKU/reference (si hay combinación, usa la referencia de la combinación).
- Obtiene stock actual y envía webhook `POST` a TS Work.
- Firma payload con `HMAC SHA256` usando `webhook_secret` compartido.
- Si falla el POST, registra error en `PrestaShopLogger`.

## Payload enviado

```json
{
  "site_id": 1,
  "sku": "ABC-123",
  "qty_new": 17,
  "event": "actionUpdateQuantity",
  "timestamp": "2026-02-17T22:00:00+00:00",
  "signature": "<hex_hmac_sha256>"
}
```

## Configuración del módulo

- `TSW_WEBHOOK_URL`: URL pública de TS Work, ej: `https://tu-dominio/api/stock_webhook.php`
- `TSW_WEBHOOK_SITE_ID`: ID del sitio en TS Work
- `TSW_WEBHOOK_SECRET`: secreto HMAC (debe coincidir con `site_connections.webhook_secret` en TS Work)
