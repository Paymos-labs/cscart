<?php

declare(strict_types=1);

use PaymosCsCart\CheckoutProcessor;
use PaymosCsCart\InMemoryInvoiceStore;

function test_cscart_checkout_creates_invoice_and_stores_snapshot()
{
    paymos_cscart_reset_test_state();
    paymos_cscart_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.test',
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
            ),
        ),
    )");

    $invoices = new FakePaymosInvoices();
    $store = new InMemoryInvoiceStore();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $result = $processor->start(42, cscart_order(), cscart_processor_params());

    assertSameValue('inv_123', $result['invoice_id'], 'CS-Cart checkout must return Paymos invoice id.');
    assertSameValue('https://paymos.test/pay/inv_123', $result['payment_url'], 'CS-Cart checkout must return hosted checkout URL.');
    assertSameValue('prj_123', $invoices->payloads[0]['project_id'], 'CS-Cart checkout payload must include project id.');
    assertSameValue('100.00', $invoices->payloads[0]['amount'], 'CS-Cart checkout payload must keep order amount as a string.');
    assertSameValue('USD', $invoices->payloads[0]['currency'], 'CS-Cart checkout payload must use order currency.');
    assertSameValue('cscart_42_0', $invoices->payloads[0]['external_order_id'], 'CS-Cart checkout payload must use stable external order id.');
    assertSameValue('77', $invoices->payloads[0]['client_id'], 'CS-Cart checkout must use user_id as client_id, not email.');

    $row = $store->findByCsCartOrderId(42);
    assertSameValue('inv_123', $row['paymos_invoice_id'], 'CS-Cart checkout must store invoice snapshot.');
    assertSameValue('100.00', $row['amount'], 'CS-Cart checkout must store amount snapshot.');
}

function test_cscart_checkout_invoices_in_primary_currency_not_secondary()
{
    paymos_cscart_reset_test_state();
    paymos_cscart_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.test',
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
            ),
        ),
    )");

    $invoices = new FakePaymosInvoices();
    $store = new InMemoryInvoiceStore();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    // Order total is denominated in the primary currency (EUR); the storefront
    // merely displays USD. The invoice must use EUR, never the display currency.
    $order = cscart_order(array(
        'currency' => 'EUR',
        'secondary_currency' => 'USD',
        'total' => '100.00',
    ));

    $processor->start(42, $order, cscart_processor_params());

    assertSameValue('EUR', $invoices->payloads[0]['currency'], 'CS-Cart checkout must invoice in the order primary currency, not secondary_currency.');
    assertSameValue('EUR', $store->findByCsCartOrderId(42)['currency'], 'CS-Cart snapshot must store the primary currency.');
}

function test_cscart_checkout_reuses_existing_invoice_when_snapshot_matches()
{
    paymos_cscart_reset_test_state();
    paymos_cscart_write_generated_config("array(
        'mode' => 'sandbox',
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
            ),
        ),
    )");

    $invoices = new FakePaymosInvoices();
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'cscart_order_id' => 42,
        'paymos_invoice_id' => 'inv_existing',
        'external_order_id' => 'cscart_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://paymos.test/pay/inv_existing',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));

    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $result = $processor->start(42, cscart_order(), cscart_processor_params());

    // New behavior: always call the server (idempotent on external_order_id) so a
    // stale/expired cached payment_url is never reused. A matching snapshot keeps
    // the SAME external_order_id (renew_count not bumped), so the server returns
    // the live invoice for that id; reused stays '1'.
    assertSameValue('1', $result['reused'], 'CS-Cart checkout must mark a matching snapshot as reused.');
    assertSameValue(1, count($invoices->payloads), 'CS-Cart checkout must call the server (idempotent) even on a matching snapshot.');
    assertSameValue('cscart_42_0', $invoices->payloads[0]['external_order_id'], 'Matching snapshot must reuse the same external_order_id, not bump it.');
}
