<?php
declare(strict_types=1);

function test_cscart_paid_recovers_before_and_after_cms_commit()
{
    paymos_cscart_reset_test_state();
    paymos_cscart_write_generated_config("array('mode' => 'sandbox', 'environments' => array('sandbox' => array(
        'api_key' => 'pk_test_123', 'api_secret' => 'sk_test_123', 'project_id' => 'prj_123', 'webhook_secret' => 'whsec_sandbox')))");
    foreach (array('failBeforePayment', 'failAfterPayment') as $fault) {
        $store = new PaymosCsCart\InMemoryInvoiceStore();
        $store->save(array('cscart_order_id' => 42, 'paymos_invoice_id' => 'inv_123',
            'external_order_id' => 'cscart_42_0', 'environment' => 'sandbox', 'project_id' => 'prj_123',
            'amount' => '100.00', 'currency' => 'USD', 'payment_url' => 'https://paymos.test/pay/inv_123',
            'status' => 'awaiting_client', 'renew_count' => 0));
        $adapter = new FakeCsCartAdapter();
        $adapter->$fault = true;
        $processor = new PaymosCsCart\WebhookProcessor($adapter, $store,
            new CommitAwareTestEventStore(), static function () { return cscart_reverse_verification_client(); });
        $body = json_encode(cscart_invoice_event('evt_crash_' . $fault, 'invoice.paid', 'paid'));
        $sig = cscart_signed_header('whsec_sandbox', $body, 1709000000);
        $first = $processor->handle($body, $sig, cscart_processor_params(), 1709000000);
        assertSameValue(false, $first->statusCode() === 200, 'Fault must remain retriable.');
        assertSameValue('awaiting_client', $store->findByExternalOrderId('cscart_42_0')['status'], 'No premature paid snapshot.');
        $second = $processor->handle($body, $sig, cscart_processor_params(), 1709000000);
        assertSameValue(200, $second->statusCode(), 'Retry recovers.');
        assertSameValue('paid', $store->findByExternalOrderId('cscart_42_0')['status'], 'Retry finalizes snapshot.');
        assertSameValue(1, count($adapter->finished), 'CMS payment happens once.');
    }
}
