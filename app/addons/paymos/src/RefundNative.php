<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Tygh\Registry;

/** Fresh native data, without order-status or payment completion hooks. */
final class RefundNative
{
    public static function load(int $orderId, RefundDatabase $db): array
    {
        if (!function_exists('fn_get_order_info') || !function_exists('fn_get_processor_data')) {
            throw new \RuntimeException('refund_native_unavailable');
        }
        $raw = $db->query('SELECT * FROM ?:orders WHERE order_id = ' . $orderId . ' FOR UPDATE')->row;
        $info = fn_get_order_info($orderId);
        if (!is_array($info) || !$raw) {
            throw new \RuntimeException('refund_order_missing');
        }
        // DECIMAL from the native row avoids float conversion in order presentation.
        $order = array_replace($info, $raw);
        $processor = fn_get_processor_data((int) $order['payment_id']);
        $params = isset($processor['processor_params']) ? $processor['processor_params'] : array();
        $currency = defined('CART_PRIMARY_CURRENCY') ? (string) CART_PRIMARY_CURRENCY : '';
        $currencyRow = $db->query("SELECT decimals FROM ?:currencies WHERE currency_code = '" . $db->escape($currency) . "'")->row;
        $rows = $db->query('SELECT * FROM ?:paymos_invoices WHERE cscart_order_id = ' . $orderId . ' ORDER BY id DESC LIMIT 2')->rows;
        $manual = false;
        foreach (array('refunded_amount', 'refund_amount') as $field) {
            if (isset($info['payment_info'][$field]) && (string) $info['payment_info'][$field] !== '0'
                && (string) $info['payment_info'][$field] !== '0.00') {
                $manual = true;
            }
        }
        $rma = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
            . $db->escape($db->table('rma_returns')) . "'")->row;
        if ($rma && $db->query('SELECT return_id FROM ?:rma_returns WHERE order_id = ' . $orderId . ' LIMIT 1')->row) {
            $manual = true;
        }
        return array(
            'order' => $order, 'processor' => $processor, 'params' => $params,
            'snapshot' => count($rows) ? $rows[0] : null,
            'currency' => $currency,
            'decimals' => isset($currencyRow['decimals']) ? (int) $currencyRow['decimals'] : null,
            'manual_refund' => $manual,
            'version' => defined('PRODUCT_VERSION') ? (string) PRODUCT_VERSION : '',
            'connection' => CredentialStore::loadCredentials(),
            'company_id' => (int) Registry::get('runtime.company_id'),
        );
    }
}
