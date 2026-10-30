<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Webhook\WebhookEvent;
use PaymosCsCart\RefundAdapter;
use PaymosCsCart\RefundDatabase;
use PaymosCsCart\RefundIntentStore;

/** SQL test double. It does not attest MySQL or a native CS-Cart package. */
final class CsCartRefundTestDb extends RefundDatabase
{
    public $intents = array();
    public $projections = array();
    public $unbound = array();
    public $installation = array();
    public $locked = false;
    public $owned = true;
    public $transaction = false;
    public $clock = 1800000000;
    public $crashBeforeCommit = false;
    public $lostCommitAck = false;
    public $engine = 'InnoDB';
    public $projectionWrites = 0;
    private $backup;
    private $affected = 0;

    public function escape(string $value): string { return addslashes($value); }
    public function table(string $name): string { return 'cscart_' . $name; }
    public function countAffected(): int { return $this->affected; }

    public function query(string $sql)
    {
        $rows = array();
        $this->affected = 0;
        if ($sql === 'START TRANSACTION') {
            assertFalseValue($this->transaction, 'No nested DB transaction.');
            $this->backup = array($this->intents, $this->projections, $this->projectionWrites, $this->unbound);
            $this->transaction = true;
        } elseif ($sql === 'COMMIT') {
            if ($this->crashBeforeCommit && count($this->projections) > count($this->backup[1])) {
                $this->crashBeforeCommit = false;
                throw new RuntimeException('simulated crash before native commit');
            }
            $newProjection = count($this->projections) > count($this->backup[1]);
            $this->transaction = false;
            $this->backup = null;
            if ($this->lostCommitAck && $newProjection) {
                $this->lostCommitAck = false;
                throw new RuntimeException('simulated lost native commit acknowledgement');
            }
        } elseif ($sql === 'ROLLBACK') {
            if ($this->backup !== null) {
                list($this->intents, $this->projections, $this->projectionWrites, $this->unbound) = $this->backup;
            }
            $this->backup = null;
            $this->transaction = false;
        } elseif (strpos($sql, 'SELECT GET_LOCK(') === 0) {
            $rows[] = array('acquired' => $this->locked ? '0' : '1');
            $this->locked = true;
        } elseif (strpos($sql, 'SELECT IS_USED_LOCK(') === 0) {
            $rows[] = array('owned' => $this->locked && $this->owned ? '1' : '0');
        } elseif (strpos($sql, 'SELECT RELEASE_LOCK(') === 0) {
            $this->locked = false;
        } elseif (strpos($sql, 'SELECT ENGINE FROM information_schema.') === 0) {
            $rows[] = array('ENGINE' => $this->engine);
        } elseif (strpos($sql, 'INSERT IGNORE INTO `?:paymos_refund_installation`') === 0) {
            if (!$this->installation) {
                preg_match("/VALUES \(1, '([^']+)'\)/", $sql, $match);
                $this->installation = array('id' => 1, 'installation_id' => $match[1]);
            }
        } elseif (strpos($sql, 'SELECT * FROM `?:paymos_refund_installation`') === 0) {
            $rows[] = $this->installation;
        } elseif (strpos($sql, 'SELECT * FROM ?:paymos_invoices WHERE paymos_invoice_id =') === 0) {
            $rows[] = array('cscart_order_id' => 42, 'project_id' => 'prj_123', 'environment' => 'sandbox');
        } elseif (preg_match('/^(INSERT INTO|UPDATE) `\?:paymos_refund_(intent|projection|unbound)` SET (.*)$/s', $sql, $match)) {
            $table = $match[2] === 'intent' ? 'intents' : ($match[2] === 'projection' ? 'projections' : 'unbound');
            $parts = explode(' WHERE ', $match[3], 2);
            $data = $this->assignments($parts[0]);
            if ($match[1] === 'INSERT INTO') {
                $id = $table === 'unbound' ? $data['withdrawal_id'] : $data['operation_id'];
                if (isset($this->{$table}[$id])) {
                    throw new RuntimeException('duplicate operation');
                }
                foreach ($this->{$table} as $saved) {
                    foreach (array('external_order_id', 'withdrawal_id') as $key) {
                        if (isset($data[$key], $saved[$key]) && $saved[$key] === $data[$key]) {
                            throw new RuntimeException('duplicate identity');
                        }
                    }
                }
                $this->{$table}[$id] = $data + array('withdrawal_id' => null, 'poll_count' => 0);
                if ($table === 'projections') {
                    assertTrueValue($this->transaction, 'Native marker shares the transaction.');
                    $this->projectionWrites++;
                }
                $this->affected = 1;
            } else {
                $conditions = $this->assignments($parts[1]);
                $id = $table === 'unbound' ? $conditions['withdrawal_id'] : $conditions['operation_id'];
                if (isset($this->{$table}[$id]) && (strpos($parts[1], '`withdrawal_id` IS NULL') === false
                    || $this->{$table}[$id]['withdrawal_id'] === null)) {
                    $this->{$table}[$id] = array_replace($this->{$table}[$id], $data);
                    if (strpos($parts[0], '`poll_count` + 1') !== false) {
                        $this->{$table}[$id]['poll_count']++;
                    }
                    $this->affected = 1;
                }
            }
        } elseif (preg_match('/^SELECT \* FROM `\?:paymos_refund_(intent|projection|unbound)` WHERE (.*)$/s', $sql, $match)) {
            $table = $match[1] === 'intent' ? 'intents' : ($match[1] === 'projection' ? 'projections' : 'unbound');
            $conditions = $this->assignments($match[2]);
            if (strpos($sql, '`next_due` >') !== false) { unset($conditions['next_due'], $conditions['operation_id']); }
            foreach ($this->{$table} as $row) {
                $include = true;
                foreach ($conditions as $key => $value) {
                    if (($row[$key] ?? null) !== $value) { $include = false; }
                }
                if (preg_match('/`next_due` <= ([0-9]+)/', $sql, $due) && $row['next_due'] > (int) $due[1]) {
                    $include = false;
                }
                if (preg_match("/`next_due` > ([0-9]+).*`operation_id` > '([^']+)'/s", $sql, $after)
                    && array($row['next_due'], $row['operation_id']) <= array((int) $after[1], $after[2])) {
                    $include = false;
                }
                if ($include) { $rows[] = $row; }
            }
            if (strpos($sql, 'ORDER BY `next_due`') !== false) {
                usort($rows, static function ($a, $b) {
                    return array($a['next_due'], $a['operation_id'] ?? $a['withdrawal_id'])
                        <=> array($b['next_due'], $b['operation_id'] ?? $b['withdrawal_id']);
                });
            }
            if (preg_match('/LIMIT ([0-9]+)/', $sql, $limit)) { $rows = array_slice($rows, 0, (int) $limit[1]); }
        } else {
            throw new RuntimeException('SQL test double does not support: ' . $sql);
        }
        return (object) array('rows' => $rows, 'row' => count($rows) ? $rows[0] : array());
    }

    private function assignments(string $sql): array
    {
        preg_match_all("/`([a-z_]+)` = ('(?:\\\\.|[^'])*'|[0-9]+|NULL)/s", $sql, $matches, PREG_SET_ORDER);
        $values = array();
        foreach ($matches as $match) {
            $values[$match[1]] = $match[2] === 'NULL' ? null
                : ($match[2][0] === "'" ? stripslashes(substr($match[2], 1, -1)) : (int) $match[2]);
        }
        return $values;
    }
}

final class CsCartRefundTestTransport implements TransportInterface
{
    public $db;
    public $posts = array();
    public $refunds = array();
    public $reads = 0;
    public $loseResponse = false;
    public $loseLock = false;
    public $corruptRead = array();
    public $errorStatus = 0;
    public $summaryAllowed = true;
    public $amounts = array('full' => '120', 'overpayment' => '20');
    public $invoiceAmount = '100';

    public function __construct(CsCartRefundTestDb $db) { $this->db = $db; }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertFalseValue($this->db->transaction, 'Provider HTTP never runs in a DB transaction.');
        if ($this->db->locked) {
            assertTrueValue(count($this->db->intents) > 0 || count($this->refunds) > 0, 'No action HTTP before a committed intent.');
        }
        assertContainsValue('HMAC-SHA256 pk_test_', $headers['Authorization'], 'The SDK signs all requests.');
        $path = parse_url($url, PHP_URL_PATH);
        if ($method === 'POST') {
            assertSameValue('/v1/refunds', $path, 'Only refund POST is used.');
            assertTrueValue($this->db->locked, 'Payment lock covers HTTP.');
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            assertSameValue(array('invoice_id', 'mode', 'external_order_id'), array_keys($payload), 'Contract allows only mode/key/invoice.');
            $found = array_filter($this->db->intents, static function ($row) use ($payload) {
                return $row['external_order_id'] === $payload['external_order_id'];
            });
            assertSameValue(1, count($found), 'An intent was committed before POST.');
            $saved = json_decode(array_values($found)[0]['payload'], true);
            assertSameValue('unknown', $saved['cash_state'], 'Uncertainty was committed before HTTP.');
            $this->posts[] = $payload;
            if ($this->errorStatus) {
                return new HttpResponse($this->errorStatus, json_encode(array('code' => 'refund_not_eligible', 'detail' => 'fixture')), array());
            }
            $key = $payload['external_order_id'];
            if (!isset($this->refunds[$key])) {
                $this->refunds[$key] = array('withdrawal_id' => 'wd_' . (count($this->refunds) + 1),
                    'invoice_id' => $payload['invoice_id'], 'project_id' => 'prj_123', 'external_order_id' => $key,
                    'mode' => $payload['mode'], 'status' => 'created', 'is_test' => true,
                    'amount' => $this->amounts[$payload['mode']], 'currency' => 'USDT', 'network' => 'TRC20',
                    'first_sender' => 'TFirstSender', 'destination_address' => 'TFirstSender');
            }
            if ($this->loseResponse) {
                $this->loseResponse = false;
                throw new RuntimeException('response lost after provider commit');
            }
            $response = $this->refunds[$key];
        } elseif ($path === '/v1/invoices/inv_123') {
            $response = array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true,
                'order' => array('external_id' => 'cscart_42_0', 'amount' => $this->invoiceAmount, 'currency' => 'USD'),
                'refund_summary' => array('can_refund_full' => $this->summaryAllowed, 'can_refund_overpayment' => $this->summaryAllowed,
                    'full_refund_amount' => $this->amounts['full'], 'overpayment_refund_amount' => $this->amounts['overpayment']));
        } else {
            $this->reads++;
            $response = null;
            foreach ($this->refunds as $row) {
                if ($path === '/v1/refunds/' . $row['withdrawal_id']) { $response = array_replace($row, $this->corruptRead); }
            }
            if ($response === null) { throw new RuntimeException('unexpected read: ' . $path); }
            if ($this->loseLock) { $this->db->owned = false; }
        }
        return new HttpResponse(200, json_encode($response), array('Cache-Control' => 'private, no-store'));
    }

    public function status(string $status): void
    {
        foreach ($this->refunds as &$row) { $row['status'] = $status; }
        unset($row);
    }
}

function cscart_refund_fixture(): array
{
    $db = new CsCartRefundTestDb();
    $store = new RefundIntentStore($db, static function () use ($db) { return new DateTimeImmutable('@' . $db->clock); });
    $transport = new CsCartRefundTestTransport($db);
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.io'), $transport);
    $native = (object) array('context' => array(
        'order' => array('order_id' => 42, 'payment_id' => 9, 'status' => 'P', 'total' => '100.00', 'company_id' => 3, 'storefront_id' => 2),
        'processor' => array('processor_script' => 'paymos.php', 'addon' => 'paymos'),
        'params' => cscart_processor_params(), 'company_id' => 3, 'manual_refund' => false, 'version' => '4.20.1',
        'currency' => 'USD', 'decimals' => 2,
        'snapshot' => array('cscart_order_id' => 42, 'paymos_invoice_id' => 'inv_123', 'external_order_id' => 'cscart_42_0',
            'project_id' => 'prj_123', 'environment' => 'sandbox', 'status' => 'paid_over', 'amount' => '100.00', 'currency' => 'USD'),
        'connection' => array('sandbox' => array('project_id' => 'prj_123', 'capabilities' => array('invoice_refunds_v1'),
            'refund_contract_version' => 1, 'refunds_enabled' => true)),
    ));
    $adapter = new RefundAdapter($store, static function () use ($native) { return $native->context; },
        static function ($environment) use ($client) {
            assertSameValue('sandbox', $environment, 'Stored environment is used.');
            return $client;
        });
    return array($db, $store, $adapter, $transport, $native);
}

function test_cscart_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    list($db, $store) = cscart_refund_fixture();
    paymos_cms_ton_pin_contract($store, 'cscart', $store->installationId(), 'cscart:42');
}


function cscart_refund_expect_error(callable $action, string $reason): void
{
    try { $action(); } catch (Exception $error) {
        assertContainsValue($reason, $error->getMessage(), 'Expected fail-closed refusal.');
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $reason);
}

function test_cscart_refund_overpayment_then_full_and_recompletion()
{
    list($db, $store, $adapter, $http, $native) = cscart_refund_fixture();
    $original = $native->context['order'];
    $over = $adapter->submit(42, 'overpayment', 'overpayment-token-0001');
    assertSameValue('created', $over->cashState(), 'Accepted is pending.');
    assertSameValue(0, count($db->projections), 'No native write on accepted.');
    $http->status('completed');
    $adapter->reconcile($over->operationId());
    $note = $store->getIntent($over->operationId())->toArray()['projection'];
    assertSameValue(null, $note['nativeAmountMinor'], 'Overpayment is cash only; refunded native total remains zero.');
    assertSameValue(null, $note['nativeCurrency'], 'No native financial currency on a cash note.');
    assertSameValue('20', $note['cryptoObservationAmount'], 'Server overpayment20 is retained.');
    assertSameValue($original, $native->context['order'], 'Order remains Paid with no stock mutation.');
    $http->amounts['full'] = '100';
    $full = $adapter->submit(42, 'full', 'full-refund-token-0001');
    $http->status('completed');
    $adapter->reconcile($full->operationId());
    $document = $store->getIntent($full->operationId())->toArray()['projection'];
    assertSameValue('10000', $document['nativeAmountMinor'], 'Full records original native total100.');
    assertSameValue('USD', $document['nativeCurrency'], 'Native total keeps native currency.');
    assertSameValue('100', $document['cryptoObservationAmount'], 'Over20 followed by server full100.');
    $http->refunds[$over->externalOrderId()]['status'] = 'failed';
    $adapter->reconcile($over->operationId());
    assertSameValue('completed', $store->getIntent($full->operationId())->cashState(), 'Old callback cannot replace full state.');
    $http->status('signed');
    $adapter->reconcile($full->operationId());
    assertSameValue('native_review', $store->getIntent($full->operationId())->projectionState(), 'Rewind retains document and warns.');
    $http->status('completed');
    $adapter->reconcile($full->operationId());
    $adapter->reconcile($full->operationId());
    assertSameValue(2, $db->projectionWrites, 'One cash note and one full record, no repeated effects.');
    assertSameValue(2, count($http->posts), 'Projection never sends another refund.');
    cscart_refund_expect_error(static function () use ($adapter) {
        $adapter->submit(42, 'overpayment', 'after-full-token-0001');
    }, 'unresolved_or_refunded');
}

function test_cscart_refund_unknown_replays_same_key_with_disabled_new_gate()
{
    list($db, $store, $adapter, $http, $native) = cscart_refund_fixture();
    $http->loseResponse = true;
    $intent = $adapter->submit(42, 'full', 'unknown-refund-token-01');
    assertSameValue('unknown', $intent->cashState(), 'Lost response is never failure.');
    assertSameValue(null, $intent->withdrawalId(), 'No callback ID is adopted.');
    $native->context['connection']['sandbox']['refunds_enabled'] = false;
    $http->status('completed');
    $adapter->reconcile($intent->operationId());
    assertSameValue($http->posts[0], $http->posts[1], 'Same immutable payload and key recover the response.');
    assertSameValue(1, count($http->refunds), 'No second provider refund.');
    assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'Recovery ignores new-submission gate.');
    $retry = $adapter->submit(42, 'full', 'unknown-refund-token-01');
    assertSameValue($intent->operationId(), $retry->operationId(), 'Same token resumes original intent after completion.');
    cscart_refund_expect_error(static function () use ($adapter) {
        $adapter->submit(42, 'overpayment', 'unknown-refund-token-01');
    }, 'refund_request_conflict');
}

function test_cscart_refund_native_crash_rollback_and_lost_ack_recover_exact_marker()
{
    foreach (array('crashBeforeCommit', 'lostCommitAck') as $failure) {
        list($db, $store, $adapter, $http) = cscart_refund_fixture();
        $intent = $adapter->submit(42, 'full', 'crash-refund-token-001');
        $http->status('completed');
        $db->{$failure} = true;
        assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Uncertain native outcome requires recovery.');
        assertSameValue($failure === 'lostCommitAck' ? 1 : 0, count($db->projections), 'Commit boundary is respected.');
        $adapter->reconcile($intent->operationId());
        assertSameValue(1, $db->projectionWrites, 'Only one committed native record survives.');
        assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'Exact marker recovers.');
    }
}

function test_cscart_refund_native_and_capability_guards_before_post()
{
    $cases = array(
        static function (&$n) { $n['manual_refund'] = true; },
        static function (&$n) { $n['order']['total'] = '99.00'; },
        static function (&$n) { $n['order']['status'] = 'O'; },
        static function (&$n) { $n['currency'] = 'EUR'; },
        static function (&$n) { $n['processor']['addon'] = 'other'; },
        static function (&$n) { $n['connection']['sandbox']['capabilities'] = array(); },
        static function (&$n) { $n['connection']['sandbox']['refund_contract_version'] = 2; },
        static function (&$n) { $n['connection']['sandbox']['refunds_enabled'] = false; },
        static function (&$n) { $n['snapshot']['project_id'] = 'prj_other'; },
        static function (&$n) { $n['company_id'] = 99; },
        static function (&$n) { $n['version'] = '4.18.1'; },
        static function (&$n) { $n['snapshot'] = null; },
    );
    foreach ($cases as $mutate) {
        list($db, $store, $adapter, $http, $native) = cscart_refund_fixture();
        $mutate($native->context);
        cscart_refund_expect_error(static function () use ($adapter) { $adapter->submit(42, 'full', 'rejected-refund-token-01'); }, 'refund_');
        assertSameValue(array(), $http->posts, 'Unsupported native case must not reach money HTTP.');
    }
    list($db, $store, $adapter, $http) = cscart_refund_fixture();
    $http->summaryAllowed = false;
    assertSameValue('review', $adapter->submit(42, 'overpayment', 'server-gate-token-0001')->cashState(), 'Server preview blocks unavailable action.');
    assertSameValue(array(), $http->posts, 'No POST when summary disallows action.');
}

function test_cscart_refund_access_csrf_and_partial_input()
{
    $auth = array('user_id' => 7, 'user_type' => 'A');
    RefundAdapter::assertAccess($auth, 'POST', array('security_hash' => 'valid'), true, 'valid');
    foreach (array(array(), array('user_id' => 7, 'user_type' => 'V')) as $badAuth) {
        cscart_refund_expect_error(static function () use ($badAuth) {
            RefundAdapter::assertAccess($badAuth, 'POST', array('security_hash' => 'valid'), true, 'valid');
        }, 'access_denied');
    }
    foreach (array(array(false, 'valid'), array(true, 'wrong'), array(true, '')) as $bad) {
        cscart_refund_expect_error(static function () use ($auth, $bad) {
            RefundAdapter::assertAccess($auth, 'POST', array('security_hash' => 'valid'), $bad[0], $bad[1]);
        }, 'access_denied');
    }
    $contract = json_decode(file_get_contents(getenv('PAYMOS_CONFORMANCE_CONTRACT') ?: dirname(__DIR__, 2) . '/sdk-conformance/contract.json'), true);
    foreach (array_merge($contract['vectors']['refunds']['create']['rejected_fields'], array('partial', 'line_items', 'restock', 'notify')) as $field) {
        cscart_refund_expect_error(static function () use ($field) { RefundAdapter::assertRequest(array($field => '20')); }, 'unsupported');
    }
}

function test_cscart_refund_binding_and_verified_money_are_write_once()
{
    foreach (array('amount' => '121', 'mode' => 'overpayment', 'project_id' => 'prj_other', 'currency' => 'BTC',
        'network' => 'ERC20', 'first_sender' => 'TAnother', 'external_order_id' => 'foreign', 'is_test' => false) as $field => $value) {
        list($db, $store, $adapter, $http) = cscart_refund_fixture();
        $intent = $adapter->submit(42, 'full', 'pinned-refund-token-001');
        $http->status('completed');
        $http->corruptRead = array($field => $value);
        $adapter->reconcile($intent->operationId());
        assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Mismatched GET cannot project.');
        assertSameValue(0, count($db->projections), 'No financial write for conflicting evidence.');
    }
    list($db, $store, $adapter) = cscart_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'binding-refund-token-01');
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) {
        assertSameValue('same', $store->bindWithdrawal($intent->operationId(), 'wd_1'), 'Same binding is idempotent.');
        assertSameValue('conflict', $store->bindWithdrawal($intent->operationId(), 'wd_foreign'), 'Changed binding is refused.');
    });
    assertSameValue('wd_1', $store->getIntent($intent->operationId())->withdrawalId(), 'Binding never changes.');
}

function test_cscart_refund_signed_callback_before_binding_and_scope()
{
    list($db, $store, $adapter, $http) = cscart_refund_fixture();
    $http->loseResponse = true;
    $intent = $adapter->submit(42, 'overpayment', 'callback-refund-token-01');
    $http->status('completed');
    $event = array('event_id' => 'evt_refund', 'event_type' => 'withdrawal.completed', 'version' => 1,
        'occurred_at' => 1709000000, 'data' => $http->refunds[$intent->externalOrderId()]);
    $event['data']['amount'] = '999';
    unset($event['data']['project_id']);
    PaymosCsCart\Config::useConfigForTests(array('environments' => array('sandbox' => array('webhook_secret' => 'whsec_sandbox'))));
    $native = new FakeCsCartAdapter();
    $processor = new PaymosCsCart\WebhookProcessor($native, new PaymosCsCart\InMemoryInvoiceStore(),
        new Paymos\Webhook\InMemoryEventStore(), null, $adapter);
    $body = json_encode($event);
    assertSameValue(401, $processor->handle($body, 'bad', array(), 1709000000)->statusCode(), 'Unsigned callbacks never touch refunds.');
    assertSameValue(200, $processor->handle($body, cscart_signed_header('whsec_sandbox', $body, 1709000000), array(), 1709000000)->statusCode(), 'Scoped signed callback recovers unknown.');
    assertSameValue('20', $store->getIntent($intent->operationId())->toArray()['projection']['cryptoObservationAmount'], 'Callback amount is ignored in favor of signed GET.');
    assertSameValue(array(), $native->finished, 'Refund never completes original native payment.');
    foreach (array('project_id' => 'prj_other', 'invoice_id' => 'inv_other', 'mode' => 'full', 'withdrawal_id' => 'wd_other', 'is_test' => false) as $field => $value) {
        $bad = $event;
        $bad['data'][$field] = $value;
        cscart_refund_expect_error(static function () use ($adapter, $bad) {
            $adapter->handleVerifiedWebhook(new WebhookEvent($bad), 'sandbox');
        }, 'scope_mismatch');
    }
    assertFalseValue($adapter->handleVerifiedWebhook(new WebhookEvent(array('event_type' => 'withdrawal.completed', 'data' => array())), 'sandbox'), 'Ordinary payouts are ignored.');
}

function test_cscart_refund_native_ambiguity_and_lock_loss_stop_projection()
{
    list($db, $store, $adapter, $http, $native) = cscart_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'changed-refund-token-01');
    $native->context['order']['payment_id'] = 11;
    $http->status('completed');
    $adapter->reconcile($intent->operationId());
    assertSameValue(0, count($db->projections), 'Changed native payment requires review.');
    list($db, $store, $adapter, $http) = cscart_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'lock-loss-refund-token1');
    $http->status('completed');
    $http->loseLock = true;
    cscart_refund_expect_error(static function () use ($adapter, $intent) { $adapter->reconcile($intent->operationId()); }, 'lock_lost');
    assertSameValue(0, count($db->projections), 'Lost DB ownership prevents every native write.');
}

function test_cscart_refund_failure_new_explicit_action_and_effect_claim()
{
    list($db, $store, $adapter, $http) = cscart_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'failed-refund-token-001');
    $http->status('failed');
    $adapter->reconcile($intent->operationId());
    $next = $adapter->submit(42, 'full', 'explicit-new-token-0001');
    assertTrueValue($intent->operationId() !== $next->operationId(), 'Definitive failure permits a fresh explicit action.');
    $http->status('completed');
    $adapter->reconcile($next->operationId());
    $store->withPaymentLock($next->paymentKey(), static function () use ($store, $next) {
        assertSameValue('claimed', $store->claimEffect($next->operationId(), 'confirmation', 1)->kind(), 'Started is committed before an optional send.');
        assertSameValue('already_started', $store->claimEffect($next->operationId(), 'confirmation', 1)->kind(), 'Lost acknowledgement cannot auto-resend.');
        $store->recordEffectOutcome($next->operationId(), 'confirmation', 1, 'unknown');
        assertSameValue('already_started', $store->claimEffect($next->operationId(), 'confirmation', 1)->kind(), 'Unknown send stays at most once.');
    });
}

function test_cscart_refund_due_backoff_historical_and_24h_review()
{
    list($db, $store, $adapter) = cscart_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'due-refund-token-00001');
    foreach (array(60, 120, 300, 900, 3600, 3600) as $delay) {
        $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
        assertSameValue($db->clock + $delay, $db->intents[$intent->operationId()]['next_due'], 'Pending uses required backoff.');
    }
    $db->clock += 86401;
    $adapter->reconcileDue(new DateTimeImmutable('@' . $db->clock));
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Unresolved24h is visible review.');
    assertSameValue(1, count($store->listDue(new DateTimeImmutable('@' . ($db->clock + 86400)), 100, null)->items()), 'Historical review remains in fair daily sweep.');
}

function test_cscart_refund_nontransactional_native_tables_refuse_post()
{
    list($db, $store, $adapter, $http) = cscart_refund_fixture();
    $db->engine = 'MyISAM';
    cscart_refund_expect_error(static function () use ($adapter) { $adapter->submit(42, 'full', 'engine-refund-token-01'); }, 'requires_innodb');
    assertSameValue(array(), $http->posts, 'Non-atomic native storage cannot start a refund.');
}

function test_cscart_refund_unbound_full_review_and_overpayment_cash_note_without_post()
{
    foreach (array('full', 'overpayment') as $mode) {
        list($db, $store, $adapter, $http) = cscart_refund_fixture();
        $wire = array('withdrawal_id' => 'wd_external', 'invoice_id' => 'inv_123', 'project_id' => 'prj_123',
            'external_order_id' => 'dashboard-refund-key', 'mode' => $mode, 'status' => 'completed', 'is_test' => true,
            'amount' => $mode === 'full' ? '120' : '20', 'currency' => 'USDT', 'network' => 'TRC20',
            'first_sender' => 'TFirstSender', 'destination_address' => 'TFirstSender');
        $http->refunds['dashboard-refund-key'] = $wire;
        $signal = $wire;
        unset($signal['project_id']);
        $event = new WebhookEvent(array('event_type' => 'withdrawal.completed', 'data' => $signal));
        $adapter->handleVerifiedWebhook($event, 'sandbox');
        $adapter->handleVerifiedWebhook($event, 'sandbox');
        assertSameValue(array(), $http->posts, 'External cash observations never initiate POST.');
        assertSameValue(array(), $db->intents, 'Unbound cash does not invent a local full draft.');
        assertSameValue(1, count($db->unbound), 'One durable observation/note per withdrawal.');
        $row = $db->unbound['wd_external'];
        if ($mode === 'full') {
            assertSameValue('refund_unbound_full_without_draft', $row['review_reason'], 'Unbound full always needs review.');
            assertSameValue(null, $row['result'], 'No financial record without a draft.');
            cscart_refund_expect_error(static function () use ($adapter) {
                $adapter->submit(42, 'full', 'after-external-token1');
            }, 'unbound_requires_review');
        } else {
            $note = json_decode($row['result'], true);
            assertSameValue(null, $note['nativeAmountMinor'], 'External overpayment cannot refund native order money.');
            $http->status('signed');
            $db->clock += 86400;
            $adapter->reconcileDue(new DateTimeImmutable('@' . $db->clock));
            assertSameValue('cash_rewound', $db->unbound['wd_external']['review_reason'], 'Historical sweep catches unbound cash rewind.');
            $http->status('completed');
            $adapter->handleVerifiedWebhook($event, 'sandbox');
            assertSameValue($row['result'], $db->unbound['wd_external']['result'], 'Recompletion retains exact cash-note marker.');
        }
    }
}

function test_cscart_refund_due_cursor_clamps_batch_and_keeps_all_history()
{
    list($db, $store) = cscart_refund_fixture();
    for ($i = 1; $i <= 105; $i++) {
        $intent = new RefundIntent(array('operation_id' => RefundIntentStore::uuid(), 'request_token' => 'pagination-token-' . $i,
            'installation_id' => $store->installationId(), 'platform' => 'cscart', 'native_payment_key' => 'cscart:' . $i,
            'invoice_id' => 'inv_' . $i, 'project_id' => 'prj_123', 'environment' => 'sandbox', 'mode' => 'overpayment',
            'native_identity' => array('order_id' => $i)));
        $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->createIntent($intent); });
    }
    $now = new DateTimeImmutable('@' . $db->clock);
    $first = $store->listDue($now, 1000, null);
    assertSameValue(100, count($first->items()), 'Batch is capped at100.');
    assertTrueValue($first->nextCursor() !== null, 'Next page is explicit.');
    $last = $store->listDue($now, 100, $first->nextCursor());
    assertSameValue(5, count($last->items()), 'History survives cursor traversal.');
    $ids = array_map(static function ($intent) { return $intent->operationId(); }, array_merge($first->items(), $last->items()));
    assertSameValue(105, count(array_unique($ids)), 'No duplicate or skipped operation across pages.');
    cscart_refund_expect_error(static function () use ($store, $now) { $store->listDue($now, 100, 'not-a-cursor'); }, 'refund_invalid_cursor');
}

function test_cscart_refund_install_and_runtime_schema_agree_and_preserve_recovery()
{
    $xml = simplexml_load_file(PAYMOS_CSCART_ADDON_DIR . 'addon.xml');
    $install = array();
    $uninstall = '';
    $normalize = static function ($value) { return preg_replace('/\s+/', ' ', trim((string) $value, " \r\n\t;")); };
    foreach ($xml->queries->item as $query) {
        if ((string) $query['for'] === 'install') { $install[] = $normalize($query); }
        if ((string) $query['for'] === 'uninstall') { $uninstall .= (string) $query; }
    }
    foreach (PaymosCsCart\RefundSchema::statements() as $statement) {
        assertTrueValue(in_array($normalize($statement), $install, true), 'Fresh install and lazy upgrade use identical refund schema.');
    }
    assertSameValue(false, strpos($uninstall, 'DROP TABLE'), 'Reinstall cannot erase refund identity or credentials.');
    assertSameValue(1, preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', (string) $xml->version), 'Plugin version must remain valid after release stamping; the package validator checks the exact planned version.');
}

function test_cscart_refund_original_native_total_keeps_large_fractional_precision()
{
    list($db, $store, $adapter, $http, $native) = cscart_refund_fixture();
    $amount = '9007199254740993.12345678';
    $native->context['order']['total'] = $amount;
    $native->context['snapshot']['amount'] = $amount;
    $native->context['decimals'] = 8;
    $http->invoiceAmount = $amount;
    $intent = $adapter->submit(42, 'full', 'precision-refund-token1');
    $http->status('completed');
    $adapter->reconcile($intent->operationId());
    assertSameValue('900719925474099312345678', $store->getIntent($intent->operationId())->toArray()['projection']['nativeAmountMinor'], 'Native accounting never uses float or FX.');
    assertSameValue('120', $store->getIntent($intent->operationId())->observation()->principalDecimal(), 'Server cash and native total remain independent.');
}

function test_cscart_refund_store_token_commit_restart_and_immutable_payload()
{
    list($db, $store, $adapter) = cscart_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'restart-refund-token-01');
    $restarted = new RefundIntentStore($db);
    $restored = $restarted->findByRequestToken($store->installationId(), 'restart-refund-token-01');
    assertSameValue($intent->toArray(), $restored->toArray(), 'A fresh store restores committed identity, money and binding.');
    $restarted->withPaymentLock($intent->paymentKey(), static function () use ($restarted, $intent) {
        $data = $intent->toArray();
        $data['full_draft']['amount_minor'] = '9999';
        unset($data['payload_hash']);
        cscart_refund_expect_error(static function () use ($restarted, $data) { $restarted->createIntent(new RefundIntent($data)); }, 'refund_request_conflict');
        $restarted->transaction(static function () use ($restarted, $intent) {
            cscart_refund_expect_error(static function () use ($restarted, $intent) { $restarted->createIntent($intent); }, 'refund_intent_requires_commit');
        });
    });
}
