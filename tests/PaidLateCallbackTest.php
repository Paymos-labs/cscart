<?php

declare(strict_types=1);

use Paymos\Webhook\InMemoryEventStore;
use PaymosCsCart\WebhookProcessor;
use PaymosCsCart\InMemoryInvoiceStore;

// invoice-late-payment: a paid_late event on an invoice whose row holds a FINAL status (expired)
// re-asserts the order's CURRENT status through finishPayment with the late fact as reason_text —
// a note, not a transition — and a sequential duplicate (distinct event id) stays silent.

function cscart_late_store()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'cscart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'cscart_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://paymos.test/pay/inv_123',
        'status' => 'expired',
        'renew_count' => 0,
    ));

    return $store;
}

function cscart_paid_late_body($eventId)
{
    return json_encode(cscart_invoice_event($eventId, 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
}

function test_cscart_paid_late_notes_with_current_status_and_changes_nothing()
{
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
    $store = cscart_late_store();
    $adapter = new FakeCsCartAdapter();
    $adapter->orders[42] = cscart_order(array('status' => 'C'));
    $processor = new WebhookProcessor($adapter, $store, new InMemoryEventStore(), static function () {
        return cscart_reverse_verification_client();
    });

    $body = cscart_paid_late_body('evt_late_1');
    $result = $processor->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);

    assertSameValue(1, count($adapter->finished), 'paid_late must produce exactly one finishPayment call.');
    assertSameValue('C', $adapter->finished[0]['response']['order_status'], 'the call re-asserts the CURRENT status — not a transition.');
    assertTrueValue(
        strpos($adapter->finished[0]['response']['reason_text'], 'arrived after the invoice deadline') !== false,
        'reason_text names the late fact.'
    );
    assertSameValue('C', $adapter->orders[42]['status'], 'the order status stays as it was.');
    assertSameValue('evt_late_1', $store->findByExternalOrderId('cscart_42_0')['paid_late_noted'], 'marker records the event id.');
    assertSameValue('expired', $store->findByExternalOrderId('cscart_42_0')['status'], 'row status stays final.');
    assertTrueValue($result->statusCode() === 200 || $result->statusCode() === 204, 'the note outcome acknowledges.');
}

function test_cscart_paid_late_sequential_duplicate_is_silent()
{
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
    $store = cscart_late_store();
    $adapter = new FakeCsCartAdapter();
    $adapter->orders[42] = cscart_order(array('status' => 'C'));
    $eventStore = new InMemoryEventStore();
    $processor = new WebhookProcessor($adapter, $store, $eventStore, static function () {
        return cscart_reverse_verification_client();
    });

    foreach (array('evt_late_1', 'evt_late_2') as $eventId) {
        $body = cscart_paid_late_body($eventId);
        $processor->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);
    }

    assertSameValue(1, count($adapter->finished), 'a sequential duplicate must not note twice.');
    assertSameValue('C', $adapter->orders[42]['status'], 'duplicates stay inert.');
}
