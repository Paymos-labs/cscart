<?php

declare(strict_types=1);

use Paymos\Webhook\InMemoryEventStore;
use PaymosCsCart\InMemoryInvoiceStore;
use PaymosCsCart\WebhookProcessor;

function test_cscart_webhook_finishes_paid_order_after_verified_webhook_and_reverse_lookup()
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
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));

    $adapter = new FakeCsCartAdapter();
    $processor = new WebhookProcessor(
        $adapter,
        $store,
        new InMemoryEventStore(),
        static function () {
            return cscart_reverse_verification_client();
        }
    );

    $body = json_encode(cscart_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $result = $processor->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'CS-Cart paid webhook must be accepted.');
    assertSameValue(42, $adapter->finished[0]['order_id'], 'CS-Cart paid webhook must finish the matching order.');
    assertSameValue('P', $adapter->finished[0]['response']['order_status'], 'CS-Cart paid webhook must use paid status.');
    assertSameValue('inv_123', $adapter->finished[0]['response']['transaction_id'], 'CS-Cart paid webhook must use invoice id as transaction id.');
}

function test_cscart_webhook_uses_confirmed_transfer_hash_as_transaction_id()
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
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));

    $adapter = new FakeCsCartAdapter();
    $processor = new WebhookProcessor(
        $adapter,
        $store,
        new InMemoryEventStore(),
        static function () {
            return cscart_reverse_verification_client();
        }
    );

    $body = json_encode(cscart_invoice_event('evt_paid_tx', 'invoice.paid', 'paid', array(
        'data' => array(
            'payment' => array(
                'transfers' => array(
                    array('tx_hash' => '0xconfirming', 'status' => 'confirming'),
                    array('tx_hash' => '0xconfirmed', 'status' => 'confirmed'),
                ),
            ),
        ),
    )));
    $result = $processor->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'CS-Cart paid webhook with transfers must be accepted.');
    assertSameValue('0xconfirmed', $adapter->finished[0]['response']['transaction_id'], 'Transaction id must be the latest confirmed on-chain tx hash, not the invoice id.');
}

function test_cscart_webhook_rejects_missing_webhook_mode()
{
    $processor = new WebhookProcessor(
        new FakeCsCartAdapter(),
        new InMemoryInvoiceStore(),
        new InMemoryEventStore(),
        static function () {
            return new FakePaymosClient();
        }
    );

    $result = $processor->handleMode('', '{}', '', cscart_processor_params(), 1709000000);

    assertSameValue(400, $result->statusCode(), 'CS-Cart callback must require mode=webhook.');
}

function test_cscart_webhook_is_idempotent_for_duplicate_events()
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
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));

    $adapter = new FakeCsCartAdapter();
    $processor = new WebhookProcessor(
        $adapter,
        $store,
        new InMemoryEventStore(),
        static function () {
            return cscart_reverse_verification_client();
        }
    );

    $body = json_encode(cscart_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = cscart_signed_header('whsec_sandbox', $body, 1709000000);

    $first = $processor->handle($body, $signature, cscart_processor_params(), 1709000000);
    $second = $processor->handle($body, $signature, cscart_processor_params(), 1709000000);

    assertSameValue(200, $first->statusCode(), 'First CS-Cart webhook must be accepted.');
    assertSameValue(200, $second->statusCode(), 'Duplicate CS-Cart webhook must be accepted.');
    assertSameValue(1, count($adapter->finished), 'Duplicate CS-Cart webhook must not finish the order twice.');
}

function test_cscart_webhook_does_not_roll_back_paid_order_on_late_cancel()
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
        'status' => 'paid',
        'renew_count' => 0,
    ));

    // The order is already in the paid status 'P'.
    $adapter = new FakeCsCartAdapter();
    $adapter->orders[42]['status'] = 'P';

    $processor = new WebhookProcessor(
        $adapter,
        $store,
        new InMemoryEventStore(),
        static function () {
            return new Paymos\Client(
                new Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test', 30),
                new Paymos\Http\MockTransport(array(
                    new Paymos\Http\HttpResponse(200, json_encode(array(
                        'invoice_id' => 'inv_123',
                        'project_id' => 'prj_123',
                        'status' => 'cancelled',
                        'order' => array(
                            'external_id' => 'cscart_42_0',
                            'amount' => '100',
                            'currency' => 'USD',
                        ),
                    )), array()),
                )),
                static function () {
                    return 1709000000;
                }
            );
        }
    );

    $body = json_encode(cscart_invoice_event('evt_late_cancel', 'invoice.cancelled', 'cancelled'));
    $result = $processor->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'Late cancel after paid must still ack with 200.');
    assertSameValue(0, count($adapter->finished), 'Late cancel must NOT downgrade an already-paid order.');
}

final class FakeCsCartAdapter implements PaymosCsCart\CsCartAdapterInterface
{
    /** @var array<int, array<string, mixed>> */
    public $orders = array();

    /** @var array<int, array<string, mixed>> */
    public $finished = array();

    /** @var array<int, array<string, mixed>> */
    public $logs = array();

    public function __construct()
    {
        $this->orders[42] = cscart_order();
    }

    public function getOrder($orderId)
    {
        return isset($this->orders[(int) $orderId]) ? $this->orders[(int) $orderId] : array();
    }

    public function finishPayment($orderId, array $ppResponse)
    {
        $this->finished[] = array(
            'order_id' => (int) $orderId,
            'response' => $ppResponse,
        );
    }

    public function log($message, array $context = array())
    {
        $this->logs[] = array(
            'message' => (string) $message,
            'context' => $context,
        );
    }
}

function cscart_reverse_verification_client()
{
    return new Paymos\Client(
        new Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test', 30),
        new Paymos\Http\MockTransport(array(
            new Paymos\Http\HttpResponse(200, json_encode(array(
                'invoice_id' => 'inv_123',
                'project_id' => 'prj_123',
                'status' => 'paid',
                'order' => array(
                    'external_id' => 'cscart_42_0',
                    // Server trims trailing zeros ("100.00" -> "100"); the snapshot
                    // is "100.00". Reverse-verify must treat them equal.
                    'amount' => '100',
                    'currency' => 'USD',
                ),
            )), array()),
        )),
        static function () {
            return 1709000000;
        }
    );
}

function test_cscart_webhook_ignores_a_stale_event_after_a_failed_invoice()
{
    // BUG-135: the invoice already ended underpaid and the order is Failed
    // ('F'). A delayed underpaid_waiting must not finish the payment again.
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
        'status' => 'underpaid',
        'renew_count' => 0,
    ));
    $adapter = new FakeCsCartAdapter();
    $adapter->orders[42]['status'] = 'F';

    $body = json_encode(cscart_invoice_event('evt_stale', 'invoice.underpaid_waiting', 'underpaid_waiting'));
    $result = (new WebhookProcessor($adapter, $store, new InMemoryEventStore()))
        ->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'a stale event is acknowledged, not retried.');
    assertSameValue(0, count($adapter->finished), 'a stale event after a final status must not move the order.');
    assertSameValue('underpaid', $store->findByExternalOrderId('cscart_42_0')['status'], 'the final status must stay recorded.');
}

final class CommitAwareTestEventStore implements Paymos\Webhook\CommitAwareEventStoreInterface
{
    /** @var array<string, bool> event id => committed */
    public $events = array();

    /** @var string */
    private $pending = '';

    public function remember($eventId, $ttlSeconds)
    {
        if (array_key_exists((string) $eventId, $this->events)) {
            return false;
        }
        $this->events[(string) $eventId] = false;
        $this->pending = (string) $eventId;
        return true;
    }

    public function isCommitted($eventId)
    {
        return !empty($this->events[(string) $eventId]);
    }

    public function commit()
    {
        if ($this->pending !== '') {
            $this->events[$this->pending] = true;
            $this->pending = '';
        }
    }

    public function release()
    {
        if ($this->pending !== '') {
            unset($this->events[$this->pending]);
            $this->pending = '';
        }
    }
}

function test_cscart_webhook_answers_409_while_the_event_is_still_being_processed()
{
    // BUG-103: another delivery of this event holds the lock and has not
    // finished. A 200 "duplicate" would mark it delivered — lost if that
    // delivery then fails. Answer 409 and leave the lock alone.
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
    $events = new CommitAwareTestEventStore();
    $events->events['evt_inflight'] = false;
    $adapter = new FakeCsCartAdapter();

    $body = json_encode(cscart_invoice_event('evt_inflight', 'invoice.paid', 'paid'));
    $result = (new WebhookProcessor($adapter, new InMemoryInvoiceStore(), $events))
        ->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), cscart_processor_params(), 1709000000);

    assertSameValue(409, $result->statusCode(), 'an event still in flight must be answered non-2xx so the server retries.');
    assertSameValue(true, array_key_exists('evt_inflight', $events->events), 'the retry must not release the lock the first delivery still holds.');
    assertSameValue(0, count($adapter->finished), 'nothing may be applied while the event is in flight.');
}
