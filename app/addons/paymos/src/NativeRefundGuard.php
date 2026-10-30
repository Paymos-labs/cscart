<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class NativeRefundGuard
{
    public static function blocksCreditMemo(int $orderId, array $order, string $status): bool
    {
        $params = fn_get_status_params($status);
        if (($params['appearance_type'] ?? '') !== 'C') {
            return false;
        }

        $processor = fn_get_processor_data((int) ($order['payment_id'] ?? 0));
        if (($processor['processor_script'] ?? '') === 'paymos.php' && ($processor['addon'] ?? '') === 'paymos') {
            return true;
        }

        // Changing the payment method must not erase the original Paymos binding.
        return (bool) db_get_field('SELECT id FROM ?:paymos_invoices WHERE cscart_order_id = ?i LIMIT 1', $orderId);
    }
}
