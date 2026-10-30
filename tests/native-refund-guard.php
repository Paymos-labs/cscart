<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// This models the documented pre-hook contract, not a licensed core package.
function fn_register_hooks(...$hooks)
{
    $GLOBALS['native_guard_hooks'] = $hooks;
}

function fn_get_status_params($status)
{
    return $GLOBALS['native_guard_statuses'][$status];
}

function fn_get_processor_data($paymentId)
{
    return $GLOBALS['native_guard_processors'][$paymentId];
}

function db_get_field($sql, $orderId)
{
    assertSameValue('SELECT id FROM ?:paymos_invoices WHERE cscart_order_id = ?i LIMIT 1', $sql, 'Only read the existing invoice binding.');
    return $GLOBALS['native_guard_bindings'][$orderId] ?? null;
}

function fn_set_notification($type, $title, $message)
{
    $GLOBALS['native_guard_notices'][] = array($type, $title, $message);
}

function __($key)
{
    return $key;
}

require PAYMOS_CSCART_ADDON_DIR . 'init.php';
require PAYMOS_CSCART_ADDON_DIR . 'func.php';

function cscart_guard_status_transition($orderId, $target, $allowed = true)
{
    $order = $GLOBALS['native_guard_orders'][$orderId];
    $from = $order['status'];
    $notify = array();
    $placing = false;
    foreach ($GLOBALS['native_guard_hooks'] as $hook) {
        $handler = 'fn_paymos_' . $hook;
        $handler($orderId, $target, $from, $notify, $placing, $order, $allowed);
    }
    if (!$allowed) {
        return false;
    }
    $GLOBALS['native_guard_recalculations']++;
    $GLOBALS['native_guard_orders'][$orderId]['status'] = $target;
    $GLOBALS['native_guard_writes'][] = array('status', $orderId, $target);
    if (fn_get_status_params($target)['appearance_type'] === 'C') {
        $GLOBALS['native_guard_orders'][$orderId]['credit_memo_id'] = 1;
        $GLOBALS['native_guard_writes'][] = array('credit_memo', $orderId);
    }
    return true;
}

$GLOBALS['native_guard_statuses'] = array(
    'R' => array('appearance_type' => 'C'),
    'X' => array('appearance_type' => 'C'),
    'P' => array('appearance_type' => 'I'),
    'C' => array('appearance_type' => 'I'),
    'O' => array('appearance_type' => 'O'),
    'F' => array('appearance_type' => 'D'),
);
$GLOBALS['native_guard_processors'] = array(
    9 => array('processor_script' => 'paymos.php', 'addon' => 'paymos'),
    10 => array('processor_script' => 'other.php', 'addon' => 'other'),
    11 => array('processor_script' => 'paymos.php', 'addon' => 'other'),
    12 => array('processor_script' => 'other.php', 'addon' => 'paymos'),
);
$GLOBALS['native_guard_orders'] = array(42 => cscart_order(array('status' => 'P', 'credit_memo_id' => null)));
$GLOBALS['native_guard_bindings'] = array();
$GLOBALS['native_guard_notices'] = array();
$GLOBALS['native_guard_writes'] = array();
$GLOBALS['native_guard_recalculations'] = 0;

$case = $argv[1];
if ($case === 'api') {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    $_SERVER['REQUEST_URI'] = '/api/orders/42';
    define('API', true);
} else {
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

if ($case === 'binding') {
    $GLOBALS['native_guard_orders'][42]['payment_id'] = 10;
    $GLOBALS['native_guard_bindings'][42] = 7;
}

if (in_array($case, array('admin', 'api', 'binding'), true)) {
    // Request flags and route names are not authority to bypass a native guard.
    $_REQUEST = array('dispatch' => 'paymos.refund', 'paymos_refund' => true);
    $before = $GLOBALS['native_guard_orders'];
    foreach (array('R', 'X') as $target) {
        assertFalseValue(cscart_guard_status_transition(42, $target), 'Native credit memo must be refused.');
    }
    assertSameValue($before, $GLOBALS['native_guard_orders'], 'The order, amount and memo ID stay intact.');
    assertSameValue(0, $GLOBALS['native_guard_recalculations'], 'Refusal precedes recalculation.');
    assertSameValue(array(), $GLOBALS['native_guard_writes'], 'Refusal precedes all writes.');
    assertSameValue(2, count($GLOBALS['native_guard_notices']), 'Both refusals are visible.');
} elseif ($case === 'other') {
    foreach (array(10, 11, 12) as $paymentId) {
        $GLOBALS['native_guard_orders'][42]['payment_id'] = $paymentId;
        assertTrueValue(cscart_guard_status_transition(42, 'X'), 'Other processors retain native credit memos.');
        assertSameValue(1, $GLOBALS['native_guard_orders'][42]['credit_memo_id'], 'Native memo still gets written.');
    }
    assertSameValue(3, $GLOBALS['native_guard_recalculations'], 'Other orders keep native recalculation.');
    assertSameValue(6, count($GLOBALS['native_guard_writes']), 'Other orders keep all native writes.');
    assertSameValue(array(), $GLOBALS['native_guard_notices'], 'No Paymos refusal for other orders.');
} elseif ($case === 'regular') {
    foreach (array('O', 'P', 'C', 'F') as $target) {
        assertTrueValue(cscart_guard_status_transition(42, $target), 'Ordinary Paymos status transitions remain allowed.');
    }
    assertFalseValue(cscart_guard_status_transition(42, 'P', false), 'Never override a previous refusal.');
    assertSameValue(4, $GLOBALS['native_guard_recalculations'], 'Allowed transitions reach native recalculation.');
    assertSameValue(4, count($GLOBALS['native_guard_writes']), 'Only allowed status writes take place.');
    assertSameValue(null, $GLOBALS['native_guard_orders'][42]['credit_memo_id'], 'No credit memo was issued.');
    assertSameValue(array(), $GLOBALS['native_guard_notices'], 'No refund refusal for ordinary transitions.');
} elseif ($case === 'paymos') {
    require __DIR__ . '/RefundAdapterTest.php';
    $before = $GLOBALS['native_guard_orders'];
    foreach (array('full', 'overpayment') as $mode) {
        list($db, $store, $adapter, $http) = cscart_refund_fixture();
        $adapter->assertOrderAccess(42);
        $intent = $adapter->submit(42, $mode, 'guard-refund-token-0001');
        assertSameValue('created', $intent->cashState(), 'Panel submission remains allowed.');
        $http->status('completed');
        $event = new \Paymos\Webhook\WebhookEvent(array(
            'event_id' => 'evt_guard_refund', 'event_type' => 'withdrawal.completed', 'version' => 1,
            'occurred_at' => 1709000000, 'data' => $http->refunds[$intent->externalOrderId()],
        ));
        assertTrueValue($adapter->handleVerifiedWebhook($event, 'sandbox'), 'Verified callback can reconcile the refund.');
        $adapter->reconcileDue(new DateTimeImmutable('@' . ($db->clock + 86400)));
        assertSameValue('completed', $store->getIntent($intent->operationId())->cashState(), 'Cron reconciliation keeps completion.');
        assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'Paymos projection remains allowed.');
        assertSameValue(1, $db->projectionWrites, 'Exactly one Paymos refund record is written.');
    }
    assertSameValue($before, $GLOBALS['native_guard_orders'], 'Paymos refunds preserve native order data.');
    assertSameValue(0, $GLOBALS['native_guard_recalculations'], 'Paymos refunds do not recalculate the native order.');
    assertSameValue(array(), $GLOBALS['native_guard_writes'], 'Paymos refunds do not issue native credit memos.');
    assertSameValue(array(), $GLOBALS['native_guard_notices'], 'The guard does not refuse Paymos refund operations.');
} else {
    throw new RuntimeException('Unknown guard case.');
}

echo 'OK ' . $case . PHP_EOL;
