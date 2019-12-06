//<?php
/**
 * Payment Sberbank Pokupay
 *
 * Sberbank credit processing
 *
 * @category    plugin
 * @version     0.1.2
 * @author      mnoskov
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender,OnBeforeCustomerNotifySending,OnOrderPlaceholdersPopulated
 * @internal    @properties &title=Название;text; &login=Логин;text; &password=Пароль;text; &is_credit=Тип кредитования;list;Кредит без переплаты==0||Кредит==1;1 &debug=Отладка запросов;list;Нет==0||Да==1;1 &test=Тестовый доступ;list;Нет==0||Да==1;1 &min_total=Минимальная сумма заказа;text; &max_total=Максимальная сумма заказа;text; &success_status_id=Статус после оформления кредита;text; &custom_lang=Имя файла с лексиконами (без .inc.php);text;
 * @internal    @modx_category Commerce
 * @internal    @installset base
*/

if (empty($modx->commerce) || !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isCurrentPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'pokupay';

$lang = $modx->commerce->getUserLanguage('pokupay');
if (!empty($custom_lang)) {
    $lang = $modx->commerce->getUserLanguage(trim($custom_lang));
}

switch ($modx->Event->name) {
    case 'OnRegisterPayments': {
        if (!$modx->isBackend()) {
            $total = $modx->commerce->getCart()->getTotal();

            if ($total) {
                if (!empty($params['min_total']) && $total < ci()->currency->convertToActive($params['min_total'])) {
                    break;
                }

                if (!empty($params['max_total']) && $total >= ci()->currency->convertToActive($params['max_total'])) {
                    break;
                }
            }
        }

        $class = new \Commerce\Payments\PokupayPayment($modx, $params);

        if (empty($params['title'])) {
            $params['title'] = $lang['pokupay.caption'];
        }

        $modx->commerce->registerPayment('pokupay', $params['title'], $class);
        break;
    }

    case 'OnBeforeOrderSending': {
        if ($isCurrentPayment) {
            $FL->setPlaceholder('extra', $FL->getPlaceholder('extra', '') . $modx->commerce->loadProcessor()->populateOrderPaymentLink($lang['pokupay.letter_link']));
        }

        break;
    }

    case 'OnBeforeCustomerNotifySending': {
        if ($reason == 'order_changed' && $isCurrentPayment) {
            $extra = !empty($params['data']['extra']) ? $params['data']['extra'] : '';
            $params['data']['extra'] = $extra . $modx->commerce->loadProcessor()->populateOrderPaymentLink($lang['pokupay.letter_link']);
        }

        break;
    }

    case 'OnOrderPlaceholdersPopulated': {
        if ($isCurrentPayment) {
            $extra = $modx->getPlaceholder('extra');
            $modx->setPlaceholder('extra', $extra . ci()->tpl->parseChunk($lang['pokupay.confirmed_text'], [
                'order' => $order,
            ]));
        }

        break;
    }

    case 'OnManagerBeforeOrderRender': {
        if (isset($params['groups']['payment_delivery']) && $isCurrentPayment) {
            $params['groups']['payment_delivery']['fields']['payment_link'] = [
                'title'   => $lang['pokupay.link_caption'],
                'content' => function($data) use ($modx) {
                    return $modx->commerce->loadProcessor()->populateOrderPaymentLink('@CODE:<a href="[+link+]" target="_blank">[+link+]</a>');
                },
                'sort' => 50,
            ];
        }

        break;
    }
}
