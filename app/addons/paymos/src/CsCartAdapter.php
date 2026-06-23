<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class CsCartAdapter implements CsCartAdapterInterface
{
    public function getOrder($orderId)
    {
        if (!function_exists('fn_get_order_info')) {
            return array();
        }

        $order = fn_get_order_info((int) $orderId);
        return is_array($order) ? $order : array();
    }

    public function finishPayment($orderId, array $ppResponse)
    {
        if (function_exists('fn_update_order_payment_info')) {
            fn_update_order_payment_info((int) $orderId, $ppResponse);
        }

        if (function_exists('fn_finish_payment')) {
            fn_finish_payment((int) $orderId, $ppResponse);
        }
    }

    public function log($message, array $context = array())
    {
        $line = '[Paymos CS-Cart] ' . (string) $message;
        if (count($context) > 0) {
            $line .= ' ' . json_encode($context);
        }

        error_log($line);
    }
}
