<?php

declare(strict_types=1);

defined('BOOTSTRAP') or die('Access denied');

require_once __DIR__ . '/src/NativeRefundGuard.php';

function fn_paymos_change_order_status_pre(
    $order_id,
    $status_to,
    $status_from,
    $force_notification,
    $place_order,
    $order_info,
    &$allow_status_update
) {
    if (!\PaymosCsCart\NativeRefundGuard::blocksCreditMemo((int) $order_id, (array) $order_info, (string) $status_to)) {
        return;
    }

    $allow_status_update = false;
    error_log('[Paymos CS-Cart] refund_access_denied order_id=' . (int) $order_id);
    fn_set_notification('E', __('error'),
        'The refund request did not complete; the reason is in the PHP error log. Check the refunds on this order before you try again.');
}
