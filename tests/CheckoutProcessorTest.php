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

final class SequencedPaymosInvoices
{
    /** @var array<int, array<string, mixed>> */
    public $payloads = array();

    /** @var array<int, string> every call in order: "create", "get <id>", "cancel <id>" */
    public $calls = array();

    /** @var array<int, mixed> one answer (an invoice array or an exception to throw) per cancel() */
    public $cancelAnswers = array();

    /** @var array<int, mixed> one answer (an invoice array or an exception to throw) per get() */
    public $getAnswers = array();

    /** @var array<int, array<string, mixed>> */
    private $responses;

    /**
     * @param array<int, array<string, mixed>> $responses One per create() call, in order.
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function create(array $payload)
    {
        $this->calls[] = 'create';
        $this->payloads[] = $payload;
        return array_shift($this->responses);
    }

    public function cancel($invoiceId, $reason)
    {
        $this->calls[] = 'cancel ' . $invoiceId;

        return self::answer(array_shift($this->cancelAnswers));
    }

    public function get($invoiceId)
    {
        $this->calls[] = 'get ' . $invoiceId;

        return self::answer(array_shift($this->getAnswers));
    }

    private static function answer($answer)
    {
        if ($answer instanceof \Exception) {
            throw $answer;
        }
        if (!is_array($answer)) {
            throw new \RuntimeException('SequencedPaymosInvoices has no queued answer.');
        }

        return $answer;
    }

    public function invoices()
    {
        return $this;
    }
}

function cscart_checkout_config_for_renewal()
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
}

function cscart_store_with_existing_link($status)
{
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
        'status' => $status,
        'renew_count' => 0,
    ));

    return $store;
}

function test_cscart_checkout_renews_when_the_server_answers_with_an_expired_invoice()
{
    // BUG-090 (CS-Cart): the checkout always asks the server, but the server
    // answers a repeated external_order_id with the SAME invoice whatever its
    // state. An expired one must be replaced, not handed to the buyer.
    cscart_checkout_config_for_renewal();
    $store = cscart_store_with_existing_link('awaiting_client');
    $invoices = new SequencedPaymosInvoices(array(
        array('invoice_id' => 'inv_existing', 'payment_url' => 'https://paymos.test/pay/inv_existing', 'status' => 'expired', 'is_final' => true),
        array('invoice_id' => 'inv_fresh', 'payment_url' => 'https://paymos.test/pay/inv_fresh', 'status' => 'awaiting_client'),
    ));
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return $invoices;
    });

    $result = $processor->start(42, cscart_order(), cscart_processor_params());

    assertSameValue(array('create', 'create'), $invoices->calls, 'the server just answered "expired": no cancel is needed.');
    assertSameValue('https://paymos.test/pay/inv_fresh', $result['payment_url'], 'an expired invoice must be replaced by a fresh one.');
    assertSameValue('0', $result['reused'], 'a replaced invoice is not reused.');
    assertSameValue('cscart_42_1', $invoices->payloads[1]['external_order_id'], 'the fresh invoice needs a new external order id.');
    assertSameValue('cscart_42_1', $store->findByCsCartOrderId(42)['external_order_id'], 'the snapshot must point at the fresh invoice.');
}

function test_cscart_checkout_renews_at_once_when_the_invoice_is_already_final()
{
    cscart_checkout_config_for_renewal();
    $store = cscart_store_with_existing_link('cancelled');
    $invoices = new SequencedPaymosInvoices(array(
        array('invoice_id' => 'inv_fresh', 'payment_url' => 'https://paymos.test/pay/inv_fresh', 'status' => 'awaiting_client'),
    ));
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return $invoices;
    });

    $result = $processor->start(42, cscart_order(), cscart_processor_params());

    assertSameValue(1, count($invoices->payloads), 'a recorded final status goes straight to a fresh invoice.');
    assertSameValue('cscart_42_1', $invoices->payloads[0]['external_order_id'], 'the fresh invoice needs a new external order id.');
    assertSameValue('https://paymos.test/pay/inv_fresh', $result['payment_url'], 'the buyer must get the fresh invoice.');
}

function test_cscart_checkout_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook. The server's answer to the create call decides; an open
    // invoice is kept under the same external order id.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        cscart_checkout_config_for_renewal();
        $store = cscart_store_with_existing_link('awaiting_client');
        $invoices = new SequencedPaymosInvoices(array(
            array('invoice_id' => 'inv_existing', 'payment_url' => 'https://paymos.test/pay/inv_existing', 'status' => $status, 'is_final' => false, 'expires_at' => time() - 3600),
            array('invoice_id' => 'inv_second', 'payment_url' => 'https://paymos.test/pay/inv_second', 'status' => 'awaiting_client'),
        ));
        $processor = new CheckoutProcessor($store, static function () use ($invoices) {
            return $invoices;
        });

        $result = $processor->start(42, cscart_order(), cscart_processor_params());

        assertSameValue('https://paymos.test/pay/inv_existing', $result['payment_url'], $status . ': the open invoice keeps its link.');
        assertSameValue('1', $result['reused'], $status . ': the open invoice is reused.');
        assertSameValue(1, count($invoices->payloads), $status . ': no second invoice is created.');
        assertSameValue('cscart_42_0', $invoices->payloads[0]['external_order_id'], $status . ': the external order id is not bumped.');
    }
}

function cscart_api_error($status, $code, $detail)
{
    return \Paymos\Exception\ApiException::fromResponse($status, json_encode(array(
        'type' => 'https://paymos.io/errors/' . $code,
        'title' => 'Error',
        'status' => $status,
        'detail' => $detail,
        'code' => $code,
    )), array());
}

function cscart_start_expecting_block(CheckoutProcessor $processor, array $order)
{
    try {
        $processor->start(42, $order, cscart_processor_params());
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('checkout must refuse to replace an invoice it cannot prove closed.');
}

function test_cscart_checkout_cancels_the_old_invoice_when_the_amount_changes()
{
    // BUG-166: a changed order gets a new external_order_id; the old invoice
    // is cancelled on the server first, or the buyer could pay both.
    cscart_checkout_config_for_renewal();
    $store = cscart_store_with_existing_link('awaiting_client');
    $invoices = new SequencedPaymosInvoices(array(
        array('invoice_id' => 'inv_fresh', 'payment_url' => 'https://paymos.test/pay/inv_fresh', 'status' => 'awaiting_client'),
    ));
    $invoices->cancelAnswers[] = array('invoice_id' => 'inv_existing', 'status' => 'cancelled');
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return $invoices;
    });

    $result = $processor->start(42, cscart_order(array('total' => '120.00')), cscart_processor_params());

    assertSameValue(array('cancel inv_existing', 'create'), $invoices->calls, 'the old invoice is cancelled before the new one is created.');
    assertSameValue('cscart_42_1', $invoices->payloads[0]['external_order_id'], 'the new invoice gets a new external order id.');
    assertSameValue('https://paymos.test/pay/inv_fresh', $result['payment_url'], 'the buyer gets the new invoice.');
    assertSameValue('cancelled', $store->findByExternalOrderId('cscart_42_0')['status'], 'the old row records the cancel, so its cancelled webhook is ignored as stale.');
}

function test_cscart_checkout_cancels_an_unstarted_invoice_past_its_deadline_before_replacing_it()
{
    cscart_checkout_config_for_renewal();
    $store = cscart_store_with_existing_link('awaiting_client');
    $invoices = new SequencedPaymosInvoices(array(
        array('invoice_id' => 'inv_existing', 'payment_url' => 'https://paymos.test/pay/inv_existing', 'status' => 'awaiting_client', 'is_final' => false, 'expires_at' => time() - 3600),
        array('invoice_id' => 'inv_fresh', 'payment_url' => 'https://paymos.test/pay/inv_fresh', 'status' => 'awaiting_client'),
    ));
    $invoices->cancelAnswers[] = array('invoice_id' => 'inv_existing', 'status' => 'cancelled');
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return $invoices;
    });

    $result = $processor->start(42, cscart_order(), cscart_processor_params());

    assertSameValue(array('create', 'cancel inv_existing', 'create'), $invoices->calls, 'read (idempotent create), cancel, create.');
    assertSameValue('https://paymos.test/pay/inv_fresh', $result['payment_url'], 'the buyer gets the new invoice.');
}

function test_cscart_checkout_does_not_replace_an_invoice_the_buyer_can_still_pay()
{
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        cscart_checkout_config_for_renewal();
        $store = cscart_store_with_existing_link('awaiting_client');
        $invoices = new SequencedPaymosInvoices(array());
        $invoices->cancelAnswers[] = cscart_api_error(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status ' . $status . '.');
        $invoices->getAnswers[] = array('invoice_id' => 'inv_existing', 'status' => $status);
        $processor = new CheckoutProcessor($store, static function () use ($invoices) {
            return $invoices;
        });

        $e = cscart_start_expecting_block($processor, cscart_order(array('total' => '120.00')));

        assertSameValue($status, $e->result()->status(), $status . ': the blocking status is reported.');
        assertSameValue(array('cancel inv_existing', 'get inv_existing'), $invoices->calls, $status . ': cancel, read, and no create.');
        assertSameValue('cscart_42_0', $store->findByCsCartOrderId(42)['external_order_id'], $status . ': the old invoice stays the order\'s invoice.');
        assertSameValue('awaiting_client', $store->findByCsCartOrderId(42)['status'], $status . ': an open or paid status read here is not recorded.');
    }
}

function test_cscart_checkout_does_not_replace_an_invoice_the_server_answers_404_for()
{
    cscart_checkout_config_for_renewal();
    $store = cscart_store_with_existing_link('awaiting_client');
    $invoices = new SequencedPaymosInvoices(array());
    $invoices->cancelAnswers[] = cscart_api_error(404, 'not_found', 'Invoice not found.');
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return $invoices;
    });

    $e = cscart_start_expecting_block($processor, cscart_order(array('total' => '120.00')));

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_NOT_FOUND, $e->result()->reason(), 'the 404 is the reason.');
    assertSameValue(array('cancel inv_existing'), $invoices->calls, 'no invoice is created after a 404.');
}
