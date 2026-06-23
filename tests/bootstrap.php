<?php

declare(strict_types=1);

define('PAYMOS_CSCART_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PAYMOS_CSCART_ADDON_DIR', PAYMOS_CSCART_PLUGIN_DIR . 'app/addons/paymos/');

spl_autoload_register(static function ($class) {
    $prefix = 'PaymosCsCart\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $path = PAYMOS_CSCART_ADDON_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }

    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_CSCART_ADDON_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            getenv('PAYMOS_SDK_SRC')
                ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
                : null,
            dirname(rtrim(PAYMOS_CSCART_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                require $candidate;
                return;
            }
        }
    }
});

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function cscart_processor_params(array $overrides = array())
{
    return array_merge(array(
        'mode' => 'sandbox',
        'pending_status' => 'O',
        'paid_status' => 'P',
        'confirming_status' => 'O',
        'failed_status' => 'F',
        'cancelled_status' => 'D',
        'debug_logging' => 'N',
    ), $overrides);
}

function cscart_order(array $overrides = array())
{
    return array_merge(array(
        'order_id' => 42,
        'total' => '100.00',
        'secondary_currency' => 'USD',
        'currency' => 'USD',
        'user_id' => 77,
        'email' => 'buyer@example.com',
        'payment_id' => 9,
    ), $overrides);
}

function cscart_signed_header($secret, $body, $timestamp)
{
    return 't=' . (int) $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

function cscart_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'occurred_at' => 1709000000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => 'cscart_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

function paymos_cscart_reset_test_state()
{
    $config = PAYMOS_CSCART_ADDON_DIR . 'paymos-config.php';
    if (is_file($config)) {
        unlink($config);
    }

    if (class_exists('PaymosCsCart\\Config') && method_exists('PaymosCsCart\\Config', 'resetForTests')) {
        PaymosCsCart\Config::resetForTests();
    }
}

function paymos_cscart_write_generated_config($php)
{
    file_put_contents(PAYMOS_CSCART_ADDON_DIR . 'paymos-config.php', "<?php\n\nreturn " . $php . ";\n");

    if (class_exists('PaymosCsCart\\Config') && method_exists('PaymosCsCart\\Config', 'resetForTests')) {
        PaymosCsCart\Config::resetForTests();
    }
}

final class FakePaymosInvoices
{
    /** @var array<int, array<string, mixed>> */
    public $payloads = array();

    /** @var array<string, mixed> */
    private $createResponse;

    /** @var array<string, mixed> */
    private $getResponse;

    public function __construct(array $createResponse = array(), array $getResponse = array())
    {
        $this->createResponse = $createResponse ?: array(
            'invoice_id' => 'inv_123',
            'payment_url' => 'https://paymos.test/pay/inv_123',
            'status' => 'awaiting_client',
        );
        $this->getResponse = $getResponse ?: array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'cscart_42_0',
                // Server trims trailing zeros ("100.00" -> "100"); snapshot is
                // "100.00". Reverse-verify must treat them equal.
                'amount' => '100',
                'currency' => 'USD',
            ),
        );
    }

    public function create(array $payload)
    {
        $this->payloads[] = $payload;
        return $this->createResponse;
    }

    public function get($invoiceId)
    {
        return $this->getResponse;
    }
}

final class FakePaymosClient
{
    /** @var FakePaymosInvoices */
    public $invoices;

    public function __construct(FakePaymosInvoices $invoices = null)
    {
        $this->invoices = $invoices ?: new FakePaymosInvoices();
    }

    public function invoices()
    {
        return $this->invoices;
    }
}
