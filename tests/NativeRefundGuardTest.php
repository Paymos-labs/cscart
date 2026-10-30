<?php

declare(strict_types=1);

function cscart_run_native_refund_guard_case($case)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/native-refund-guard.php')
        . ' ' . escapeshellarg($case) . ' 2>&1';
    exec($command, $output, $status);
    assertSameValue(0, $status, implode("\n", $output));
}

function test_cscart_native_credit_memo_admin_refused_before_recalculation_or_write()
{
    cscart_run_native_refund_guard_case('admin');
}

function test_cscart_native_credit_memo_api_refused_before_recalculation_or_write()
{
    cscart_run_native_refund_guard_case('api');
}

function test_cscart_native_credit_memo_keeps_original_paymos_binding_after_payment_change()
{
    cscart_run_native_refund_guard_case('binding');
}

function test_cscart_native_credit_memo_non_paymos_behavior_unchanged()
{
    cscart_run_native_refund_guard_case('other');
}

function test_cscart_native_regular_paymos_statuses_and_existing_refusals_unchanged()
{
    cscart_run_native_refund_guard_case('regular');
}

function test_cscart_native_guard_keeps_paymos_refund_callback_and_due_reconciliation_allowed()
{
    cscart_run_native_refund_guard_case('paymos');
}
