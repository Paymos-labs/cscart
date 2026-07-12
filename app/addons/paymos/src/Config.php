<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\ClientConfig;

final class Config
{
    /** @var array<string, mixed>|null */
    private static $generatedConfig;

    /** @var array<string, mixed> */
    private $params;

    /** @var array<string, mixed> */
    private $generated;

    private function __construct(array $params, array $generated)
    {
        $this->params = $params;
        $this->generated = $generated;
    }

    public static function fromProcessorParams(array $params)
    {
        return new self($params, self::generatedConfig());
    }

    public static function resetForTests()
    {
        self::$generatedConfig = null;
    }

    /** @param array<string, mixed> $config */
    public static function useConfigForTests(array $config)
    {
        self::$generatedConfig = $config;
    }

    public function environment()
    {
        $mode = $this->scalar($this->params, 'mode');
        if ($mode === '') {
            $mode = $this->scalar($this->generated, 'mode');
        }

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function projectId()
    {
        return $this->environmentValue($this->environment(), 'project_id');
    }

    public function clientConfig()
    {
        return $this->clientConfigForEnvironment($this->environment());
    }

    public function clientConfigForEnvironment($environment)
    {
        $environment = $environment === 'live' ? 'live' : 'sandbox';
        $apiKey = $this->environmentValue($environment, 'api_key');
        $apiSecret = $this->environmentValue($environment, 'api_secret');
        $this->assertCredentialEnvironment($environment, $apiKey, $apiSecret);

        return new ClientConfig($apiKey, $apiSecret, $this->apiBaseUrl($environment), 30);
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets()
    {
        $secrets = array();
        foreach (array('sandbox', 'live') as $environment) {
            $secret = $this->environmentValue($environment, 'webhook_secret', false);
            if ($secret !== '') {
                $secrets[$environment] = $secret;
            }
        }

        if (count($secrets) === 0) {
            throw new \InvalidArgumentException('Paymos generated config must contain at least one webhook secret.');
        }

        return $secrets;
    }

    public function status($name)
    {
        $key = (string) $name . '_status';
        $value = $this->scalar($this->params, $key);
        if ($value !== '') {
            return $value;
        }

        switch ($name) {
            case 'paid':
                return 'P';
            case 'failed':
                return 'F';
            case 'cancelled':
                return 'D';
            case 'pending':
            case 'confirming':
            default:
                return 'O';
        }
    }

    public function debugLogging()
    {
        $value = strtoupper($this->scalar($this->params, 'debug_logging'));
        return $value === 'Y' || $value === 'YES' || $value === '1' || $value === 'TRUE';
    }

    private function apiBaseUrl($environment)
    {
        // The dashboard generator nests base_url inside each environment block
        // (environments.{sandbox,live}.base_url); there is no top-level
        // api_base_url. Default to the public host when absent.
        $url = $this->environmentValue($environment, 'base_url', false);
        return $url !== '' ? $url : 'https://api.paymos.io';
    }

    private function environmentValue($environment, $key, $required = true)
    {
        $environments = isset($this->generated['environments']) && is_array($this->generated['environments'])
            ? $this->generated['environments']
            : array();

        $config = isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();

        $value = $this->scalar($config, $key);
        if ($required && $value === '') {
            throw new \InvalidArgumentException('Paymos generated config is missing ' . $key . ' for ' . $environment . '.');
        }

        return $value;
    }

    private function assertCredentialEnvironment($environment, $apiKey, $apiSecret)
    {
        if ($environment === 'sandbox') {
            if (strpos($apiKey, '_test_') === false || strpos($apiSecret, '_test_') === false) {
                throw new \InvalidArgumentException('Sandbox mode requires *_test_* API credentials.');
            }
            return;
        }

        if (strpos($apiKey, '_live_') === false || strpos($apiSecret, '_live_') === false) {
            throw new \InvalidArgumentException('Live mode requires *_live_* API credentials.');
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalar(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function generatedConfig()
    {
        if (self::$generatedConfig !== null) {
            return self::$generatedConfig;
        }

        if (function_exists('db_get_field')) {
            try {
                $stored = CredentialStore::loadCredentials();
                if (count($stored) > 0) {
                    self::$generatedConfig = array('environments' => $stored);
                    return self::$generatedConfig;
                }
            } catch (\Throwable $exception) {
                self::$generatedConfig = array('mode' => 'sandbox', 'environments' => array());
                return self::$generatedConfig;
            }
        }

        self::$generatedConfig = array(
            'mode' => 'sandbox',
            'environments' => array(),
        );

        return self::$generatedConfig;
    }
}
