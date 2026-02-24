<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class TsWorkStockWebhook extends Module
{
    public function __construct()
    {
        $this->name = 'tsworkstockwebhook';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'TS Work';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('TS Work Stock Webhook');
        $this->description = $this->l('Envía cambios de stock de PrestaShop a TS Work por webhook firmado (HMAC).');
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue('TSW_WEBHOOK_URL', '')
            && Configuration::updateValue('TSW_WEBHOOK_SITE_ID', '')
            && Configuration::updateValue('TSW_WEBHOOK_SECRET', '')
            && $this->registerHook('actionUpdateQuantity')
            && $this->registerHook('actionValidateOrder');
    }

    public function uninstall()
    {
        Configuration::deleteByName('TSW_WEBHOOK_URL');
        Configuration::deleteByName('TSW_WEBHOOK_SITE_ID');
        Configuration::deleteByName('TSW_WEBHOOK_SECRET');
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitTsWorkStockWebhook')) {
            $url = trim((string)Tools::getValue('TSW_WEBHOOK_URL'));
            $siteId = trim((string)Tools::getValue('TSW_WEBHOOK_SITE_ID'));
            $secret = trim((string)Tools::getValue('TSW_WEBHOOK_SECRET'));

            Configuration::updateValue('TSW_WEBHOOK_URL', $url);
            Configuration::updateValue('TSW_WEBHOOK_SITE_ID', $siteId);
            Configuration::updateValue('TSW_WEBHOOK_SECRET', $secret);

            $output .= $this->displayConfirmation($this->l('Configuración guardada.'));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Webhook TS Work'),
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Webhook URL'),
                        'name' => 'TSW_WEBHOOK_URL',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Site ID en TS Work'),
                        'name' => 'TSW_WEBHOOK_SITE_ID',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Webhook secret (HMAC)'),
                        'name' => 'TSW_WEBHOOK_SECRET',
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar'),
                    'name' => 'submitTsWorkStockWebhook',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitTsWorkStockWebhook';

        $helper->fields_value = [
            'TSW_WEBHOOK_URL' => (string)Configuration::get('TSW_WEBHOOK_URL'),
            'TSW_WEBHOOK_SITE_ID' => (string)Configuration::get('TSW_WEBHOOK_SITE_ID'),
            'TSW_WEBHOOK_SECRET' => (string)Configuration::get('TSW_WEBHOOK_SECRET'),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    public function hookActionUpdateQuantity($params)
    {
        $idProduct = (int)($params['id_product'] ?? 0);
        if ($idProduct <= 0) {
            return;
        }

        $idProductAttribute = (int)($params['id_product_attribute'] ?? 0);
        $quantity = (int)($params['quantity'] ?? 0);

        $sku = $this->resolveSku($idProduct, $idProductAttribute);
        if ($sku === '') {
            PrestaShopLogger::addLog('[TsWorkStockWebhook] No se pudo resolver SKU para id_product=' . $idProduct . ' attr=' . $idProductAttribute, 2);
            return;
        }

        $this->pushStock($sku, $quantity, 'actionUpdateQuantity');
    }

    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'];
        $products = $order->getProducts();
        if (!is_array($products)) {
            return;
        }

        foreach ($products as $line) {
            $idProduct = (int)($line['product_id'] ?? 0);
            if ($idProduct <= 0) {
                continue;
            }

            $idAttr = (int)($line['product_attribute_id'] ?? 0);
            $sku = $this->resolveSku($idProduct, $idAttr);
            if ($sku === '') {
                PrestaShopLogger::addLog('[TsWorkStockWebhook] actionValidateOrder sin SKU para producto=' . $idProduct . ' attr=' . $idAttr, 2);
                continue;
            }

            $qty = (int)StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr);
            $this->pushStock($sku, $qty, 'actionValidateOrder');
        }
    }

    protected function resolveSku($idProduct, $idProductAttribute)
    {
        if ((int)$idProductAttribute > 0) {
            $combination = new Combination((int)$idProductAttribute);
            if (Validate::isLoadedObject($combination)) {
                $combinationRef = trim((string)$combination->reference);
                if ($combinationRef !== '') {
                    return $combinationRef;
                }
            }
        }

        $product = new Product((int)$idProduct, false, null, null, null, null);
        if (!Validate::isLoadedObject($product)) {
            return '';
        }

        return trim((string)$product->reference);
    }

    protected function pushStock($sku, $qty, $event)
    {
        $url = trim((string)Configuration::get('TSW_WEBHOOK_URL'));
        $siteId = trim((string)Configuration::get('TSW_WEBHOOK_SITE_ID'));
        if ($url === '' || $siteId === '') {
            PrestaShopLogger::addLog('[TsWorkStockWebhook] Faltan TSW_WEBHOOK_URL / SITE_ID.', 2);
            return;
        }

        $payload = [
            'shop_id' => (int)$siteId,
            'sku' => (string)$sku,
            'stock' => (int)$qty,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code < 200 || $code >= 300) {
            PrestaShopLogger::addLog('[TsWorkStockWebhook] Falló webhook. HTTP=' . $code . ' cURL=' . $curlErr . ' payload=' . json_encode($payload), 3);
        }
    }
}
