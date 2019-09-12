<?php

namespace Commerce\Payments;

class PokupayPayment extends Payment implements \Commerce\Interfaces\Payment
{
    private $codes = [
        'USD' => 840,
        'EUR' => 978,
        'RUB' => 643,
        'RUR' => 643,
        'BYN' => 933,
    ];

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('pokupay');
        if (!empty($params['custom_lang'])) {
            $this->lang = $modx->commerce->getUserLanguage(trim($params['custom_lang']));
        }
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('token')) && (empty($this->getSetting('login')) || empty($this->getSetting('password')))) {
            return '<span class="error" style="color: red;">' . $this->lang['pokupay.error_empty_token_and_login_password'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $order_id  = $order['id'];
        $amount    = $order['amount'] * 100;
        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], ci()->currency->convertToDefault($order['amount'], $currency['code']));

        $customer = [];

        if (!empty($order['name']) && is_string($order['name'])) {
            $customer['contact'] = mb_substr($order['name'], 0, 255);
        }

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = $order['email'];
        }

        if (!empty($order['phone'])) {
            $phone = preg_replace('/[^0-9]+/', '', $order['phone']);
            $phone = preg_replace('/^8/', '7', $phone);

            if (preg_match('/^7\d{10}$/', $phone)) {
                $customer['phone'] = $phone;
            }
        }

        $cart = $processor->getCart();
        $items = $subtotals = [];
        $position = 1;

        foreach ($cart->getItems() as $item) {
            $items[] = [
                'positionId'  => $position++,
                'name'        => $item['name'],
                'quantity'    => [
                    'value'   => $item['count'],
                    'measure' => isset($meta['measurements']) ? $meta['measurements'] : $this->lang['measures.units'],
                ],
                'itemAmount'  => $item['price'] * $item['count'] * 100,
                'itemPrice'   => $item['price'] * 100,
                'itemCode'    => $item['id'],
            ];
        }

        $cart->getSubtotals($subtotals, $total);

        foreach ($subtotals as $item) {
            $items[] = [
                'positionId'  => $position++,
                'name'        => $item['title'],
                'quantity'    => [
                    'value'   => 1,
                    'measure' => '-',
                ],
                'itemAmount'  => $item['price'] * 100,
                'itemPrice'   => $item['price'] * 100,
                'itemCode'    => $item['id'],
            ];
        }

        $params = [
            'CMS' => 'Evolution CMS ' . $this->modx->getConfig('settings_version'),
        ];

        foreach (['email', 'phone'] as $field) {
            if (isset($customer[$field])) {
                $params[$field] = $customer[$field];
            }
        }

        $currency = ci()->currency->getCurrencyCode();

        if (isset($this->codes[$currency])) {
            $currency = $this->codes[$currency];
        } else {
            $this->modx->logEvent(0, 3, 'Unknown currency: ' . print_r($currency, true), 'Commerce Pokupay Payment');
            return false;
        }

        $data = [
            'orderNumber' => $order_id . '-' . time(),
            'amount'      => $amount,
            'currency'    => $currency,
            'language'    => 'ru',
            'returnUrl'   => $this->modx->getConfig('site_url') . 'commerce/pokupay/payment-process/?' . http_build_query([
                'paymentId'   => $payment['id'],
                'paymentHash' => $payment['hash'],
            ]),
            'description' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                'order_id'  => $order_id,
                'site_name' => $this->modx->getConfig('site_name'),
            ]),
            'orderBundle' => json_encode([
                'orderCreationDate' => date('c'),
                'customerDetails'   => $customer,
                'cartItems' => [
                    'items' => $items,
                ],
                'installments' => [
                    'productType' => $this->getSetting('is_credit', 1) ? 'CREDIT' : 'INSTALLMENT',
                    'productID'   => '10',
                ],
            ]),
            'jsonParams' => json_encode($params),
        ];

        if (!empty($this->getSetting('test'))) {
            $data['dummy'] = 1;
        }

        try {
            $result = $this->request('register.do', $data);

            if (empty($result['formUrl'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Link is not received: ' . $e->getMessage(), 'Commerce Pokupay Payment');
            return false;
        }

        return $result['formUrl'];
    }

    public function handleCallback()
    {
        if (isset($_REQUEST['mdOrder']) && is_string($_REQUEST['mdOrder']) && isset($_REQUEST['paymentId']) && is_numeric($_REQUEST['paymentId']) && isset($_REQUEST['paymentHash']) && is_string($_REQUEST['paymentHash'])) {
            $order_id = $_REQUEST['mdOrder'];

            try {
                $status = $this->request('getOrderStatusExtended.do', [
                    'orderId' => $order_id,
                ]);
            } catch (\Exception $e) {
                $this->modx->logEvent(0, 3, 'Order status request failed: ' . $e->getMessage(), 'Commerce Pokupay Payment');
                return false;
            }

            if (empty($status['errorCode'])) {
                $processor = $this->modx->commerce->loadProcessor();

                try {
                    $processor->processPayment($_REQUEST['paymentId'], floatval($status['amount']), $this->getSetting('success_status_id'));
                } catch (\Exception $e) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Pokupay Payment');
                    return false;
                }

                $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/pokupay/payment-success?paymentHash=' . $_REQUEST['paymentHash']);
            }
        }

        return false;
    }

    protected function getUrl($method)
    {
        $url = ($this->getSetting('test') == 1 ? 'https://3dsec.sberbank.ru' : 'https://securepayments.sberbank.ru') . '/sbercredit/';
        return $url . $method;
    }

    protected function request($method, $data)
    {
        $data['token'] = $this->getSetting('token');

        if (empty($data['token'])) {
            $data['userName'] = $this->getSetting('login');
            $data['password'] = $this->getSetting('password');
        }

        $url  = $this->getUrl($method);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE        => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-type: application/x-www-form-urlencoded',
                'Cache-Control: no-cache',
                'charset="utf-8"',
            ],
        ]);

        $result = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!empty($this->getSetting('debug'))) {
            $this->modx->logEvent(0, 1, 'URL: <pre>' . $url . '</pre><br>Data: <pre>' . htmlentities(print_r($data, true)) . '</pre><br>Response: <pre>' . $code . "\n" . htmlentities(print_r($result, true)) . '</pre><br>', 'Commerce Pokupay Payment Debug');
        }

        if ($code != 200) {
            $this->modx->logEvent(0, 3, 'Server is not responding', 'Commerce Pokupay Payment');
            return false;
        }

        $result = json_decode($result, true);

        if (!empty($result['errorCode']) && isset($result['errorMessage'])) {
            $this->modx->logEvent(0, 3, 'Server return error: ' . $result['errorMessage'], 'Commerce Pokupay Payment');
            return false;
        }

        return $result;
    }

    public function getRequestPaymentHash()
    {
        if (isset($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }
}
