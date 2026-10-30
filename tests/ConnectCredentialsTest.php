<?php

declare(strict_types=1);

// Separate processes isolate the controller's exit and platform function doubles.
if (!isset($argv[1])) {
    $cases = array('enabled', 'missing_capability', 'missing_contract', 'disabled', 'legacy', 'strict_flags');
    foreach ($cases as $case) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case);
        passthru($command, $status);
        if ($status !== 0) {
            exit($status);
        }
    }
    echo 'OK ' . count($cases) . " Connect tests\n";
    exit;
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/RefundAdapterTest.php';

final class CsCartConnectRegistry
{
    public static function get($key)
    {
        if ($key === 'config.crypt_key') {
            return 'cscart-connect-test-key';
        }
        if ($key === 'runtime.company_id') {
            return 3;
        }
        throw new RuntimeException('Unexpected registry key: ' . $key);
    }
}

class_alias('CsCartConnectRegistry', 'Tygh\\Registry');

$GLOBALS['cscart_connect_config'] = array();

function db_query($sql, ...$args)
{
    if ($sql === 'REPLACE INTO ?:paymos_config ?e') {
        $row = $args[0];
        $GLOBALS['cscart_connect_config'][$row['name']] = $row['value'];
        return true;
    }
    if ($sql === 'DELETE FROM ?:paymos_config WHERE name = ?s') {
        unset($GLOBALS['cscart_connect_config'][$args[0]]);
        return true;
    }
    throw new RuntimeException('Unexpected config write: ' . $sql);
}

function db_get_field($sql, ...$args)
{
    assertSameValue('SELECT value FROM ?:paymos_config WHERE name = ?s', $sql, 'Read the native config table.');
    return $GLOBALS['cscart_connect_config'][$args[0]] ?? '';
}

function fn_url($url, $area, $protocol)
{
    return 'https://cscart.example.test/';
}

function fn_get_order_info($orderId)
{
    assertSameValue(42, $orderId, 'Load the mapped native order.');
    return $GLOBALS['cscart_connect_native']['order'];
}

function fn_get_processor_data($paymentId)
{
    assertSameValue(9, $paymentId, 'Load the original payment method.');
    return $GLOBALS['cscart_connect_native']['processor']
        + array('processor_params' => $GLOBALS['cscart_connect_native']['params']);
}

final class CsCartConnectNativeDb extends \PaymosCsCart\RefundDatabase
{
    public function query(string $sql)
    {
        $native = $GLOBALS['cscart_connect_native'];
        if ($sql === 'SELECT * FROM ?:orders WHERE order_id = 42 FOR UPDATE') {
            $rows = array($native['order']);
        } elseif ($sql === "SELECT decimals FROM ?:currencies WHERE currency_code = 'USD'") {
            $rows = array(array('decimals' => '2'));
        } elseif ($sql === 'SELECT * FROM ?:paymos_invoices WHERE cscart_order_id = 42 ORDER BY id DESC LIMIT 2') {
            $rows = array($native['snapshot']);
        } elseif (strpos($sql, 'SELECT TABLE_NAME FROM information_schema.TABLES ') === 0) {
            $rows = array();
        } else {
            throw new RuntimeException('Unexpected native read: ' . $sql);
        }
        return (object) array('rows' => $rows, 'row' => $rows ? $rows[0] : array());
    }

    public function escape(string $value): string { return addslashes($value); }
    public function table(string $name): string { return 'cscart_' . $name; }
}

final class CsCartConnectTransport implements \Paymos\Http\TransportInterface
{
    public static $calls = 0;
    public static $credentials;

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        self::$calls++;
        assertSameValue('POST', $method, 'Connect polls with POST.');
        assertSameValue('https://app.paymos.io/connect/token', $url, 'Only the Connect token endpoint is called.');
        parse_str($body, $fields);
        assertSameValue('device-code-fixture', $fields['device_code'], 'Poll uses the persisted device code.');
        return new \Paymos\Http\HttpResponse(200, json_encode(array('paymos_config' => array(
            'plugin' => 'cscart', 'source_url' => 'https://cscart.example.test',
            'environments' => self::$credentials,
        ))), array());
    }
}

// The real DeviceConnectClient uses this transport without making network calls.
class_alias('CsCartConnectTransport', 'Paymos\\Http\\CurlTransport');

final class CsCartConnectRefundTransport implements \Paymos\Http\TransportInterface
{
    public $calls = 0;
    private $inner;

    public function __construct(CsCartRefundTestTransport $inner) { $this->inner = $inner; }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->calls++;
        return $this->inner->request($method, $url, $headers, $body, $timeoutSeconds);
    }
}

$case = $argv[1];
$credentials = array();
foreach (array('sandbox' => 'test', 'live' => 'live') as $environment => $prefix) {
    $credentials[$environment] = array(
        'base_url' => 'https://api.paymos.io',
        'api_key' => 'pk_' . $prefix . '_connect',
        'api_secret' => 'sk_' . $prefix . '_connect',
        'project_id' => $environment === 'sandbox' ? 'prj_123' : 'prj_live_connect',
        'webhook_secret' => 'whsec_' . $environment . '_connect',
        'capabilities' => $environment === 'sandbox' ? array('invoice_refunds_v1', 'other_capability') : array(),
        'refund_contract_version' => 1,
        'refunds_enabled' => $environment === 'sandbox',
    );
}
$reason = null;
switch ($case) {
    case 'enabled':
        break;
    case 'missing_capability':
        $credentials['sandbox']['capabilities'] = array('other_capability');
        $reason = 'refund_reconnect_required';
        break;
    case 'missing_contract':
        unset($credentials['sandbox']['refund_contract_version']);
        $reason = 'refund_reconnect_required';
        break;
    case 'disabled':
        $credentials['sandbox']['refunds_enabled'] = false;
        $reason = 'refund_submissions_disabled';
        break;
    case 'legacy':
        unset($credentials['sandbox']['capabilities'], $credentials['sandbox']['refund_contract_version'],
            $credentials['sandbox']['refunds_enabled']);
        $reason = 'refund_reconnect_required';
        break;
    case 'strict_flags':
        $credentials['sandbox']['refunds_enabled'] = 'true';
        $reason = 'refund_submissions_disabled';
        break;
    default:
        throw new RuntimeException('Unknown Connect test case.');
}
CsCartConnectTransport::$credentials = $credentials;
\PaymosCsCart\CredentialStore::saveState(array('device_code' => 'device-code-fixture', 'expires_in' => 600));
\PaymosCsCart\Config::useConfigForTests(array('environments' => array()));
define('PRODUCT_VERSION', '4.20.1');
define('CART_PRIMARY_CURRENCY', 'USD');
$_SERVER['REQUEST_METHOD'] = 'POST';
$mode = 'connect_poll';
ob_start();

register_shutdown_function(static function () use ($case, $credentials, $reason) {
    $response = ob_get_clean();
    try {
        assertSameValue(array('status' => 'connected'), json_decode($response, true), 'The real Connect controller completes.');
        assertSameValue(1, CsCartConnectTransport::$calls, 'Connect polls exactly once.');
        assertFalseValue(isset($GLOBALS['cscart_connect_config']['connect_state_v1']), 'Successful Connect clears its state.');
        $encoded = $GLOBALS['cscart_connect_config']['credentials_v1'];
        $payload = \Paymos\Plugin\AesGcmEnvelope::open($encoded, 'cscart-connect-test-key', 'paymos-cscart-credentials-v1');
        assertSameValue(1, $payload['schema'], 'Credential storage keeps its schema.');
        $loaded = \PaymosCsCart\CredentialStore::loadCredentials();
        foreach ($credentials as $environment => $values) {
            $expected = array_replace($values, array(
                'capabilities' => $values['capabilities'] ?? array(),
                'refund_contract_version' => $values['refund_contract_version'] ?? null,
                'refunds_enabled' => ($values['refunds_enabled'] ?? null) === true,
            ));
            foreach ($expected as $field => $value) {
                assertSameValue($value, $payload['environments'][$environment][$field], 'Encrypted payload preserves ' . $environment . '.' . $field);
                assertSameValue($value, $loaded[$environment][$field], 'Reload preserves ' . $environment . '.' . $field);
            }
            foreach (array('api_secret', 'webhook_secret') as $field) {
                assertSameValue(false, strpos($encoded, $values[$field]), 'Secrets stay encrypted.');
            }
        }
        $config = \PaymosCsCart\Config::fromProcessorParams(cscart_processor_params());
        assertSameValue('pk_test_connect', $config->clientConfig()->apiKey(), 'Connect resets cached credentials.');
        assertSameValue('sk_test_connect', $config->clientConfig()->apiSecret(), 'The original API secret reaches the client.');
        assertSameValue(array('sandbox' => 'whsec_sandbox_connect', 'live' => 'whsec_live_connect'),
            $config->webhookSecrets(), 'Both webhook secrets stay available.');

        list($db, $store, $unusedAdapter, $http, $native) = cscart_refund_fixture();
        $GLOBALS['cscart_connect_native'] = $native->context;
        $nativeDb = new CsCartConnectNativeDb();
        $context = \PaymosCsCart\RefundNative::load(42, $nativeDb);
        assertSameValue($loaded, $context['connection'], 'RefundNative passes the stored connection to the adapter.');
        $transport = new CsCartConnectRefundTransport($http);
        $adapter = new \PaymosCsCart\RefundAdapter($store,
            static function ($orderId) use ($nativeDb) { return \PaymosCsCart\RefundNative::load($orderId, $nativeDb); },
            static function ($environment, array $params) use ($transport) {
                $config = \PaymosCsCart\Config::fromProcessorParams($params)->clientConfigForEnvironment($environment);
                return new \Paymos\Client($config, $transport);
            });
        if ($reason === null) {
            $intent = $adapter->submit(42, 'full', 'connect-refund-token-01');
            assertSameValue('created', $intent->cashState(), 'Stored capability permits the refund.');
            assertSameValue(1, count($http->posts), 'Exactly one refund is submitted.');
        } else {
            try {
                $adapter->submit(42, 'full', 'connect-refund-token-01');
                throw new RuntimeException('Expected refund refusal.');
            } catch (RuntimeException $error) {
                assertSameValue($reason, $error->getMessage(), 'Stored metadata determines the refusal.');
            }
            assertSameValue(0, $transport->calls, 'Refusal precedes every refund and invoice HTTP request.');
            assertSameValue(array(), $db->intents, 'Refusal does not create a refund intent.');
        }
        echo 'PASS Connect ' . $case . "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL Connect ' . $case . ': ' . $error->getMessage() . "\n");
        exit(1);
    }
});

require PAYMOS_CSCART_ADDON_DIR . 'controllers/backend/paymos.php';
