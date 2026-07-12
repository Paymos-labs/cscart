<?php

declare(strict_types=1);

defined('BOOTSTRAP') or die('Access denied');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return array(CONTROLLER_STATUS_DENIED);
}

require_once dirname(__DIR__, 2) . '/src/Autoloader.php';
\PaymosCsCart\Autoloader::register();

header('Content-Type: application/json');
try {
    $sourceUrl = rtrim((string) fn_url('', 'C', 'https'), '/');
    if ($sourceUrl === '' || stripos($sourceUrl, 'https://') !== 0) {
        throw new \RuntimeException('CS-Cart storefront URL must use HTTPS.');
    }
    $client = new \Paymos\Connect\DeviceConnectClient('https://app.paymos.io');
    if ($mode === 'connect_start') {
        $state = $client->start('cscart', $sourceUrl);
        \PaymosCsCart\CredentialStore::saveState($state);
        echo json_encode(array(
            'verification_url' => $state['verification_url'],
            'user_code' => $state['user_code'],
            'interval' => $state['interval'],
        ));
        exit;
    }
    if ($mode !== 'connect_poll') {
        throw new \RuntimeException('Invalid Paymos connection action.');
    }
    $state = \PaymosCsCart\CredentialStore::loadState();
    if (!isset($state['device_code'])) {
        throw new \RuntimeException('No active Paymos connection request.');
    }
    $result = $client->poll((string) $state['device_code']);
    if ($result['status'] === 'connected') {
        if ($result['plugin'] !== 'cscart' || rtrim((string) $result['source_url'], '/') !== $sourceUrl) {
            throw new \RuntimeException('Paymos connection response does not match this CS-Cart storefront.');
        }
        \PaymosCsCart\CredentialStore::saveCredentials($result['credentials']);
        \PaymosCsCart\CredentialStore::clearState();
        \PaymosCsCart\Config::resetForTests();
        echo json_encode(array('status' => 'connected'));
        exit;
    }
    if (in_array($result['status'], array('authorization_pending', 'slow_down'), true)) {
        echo json_encode(array('status' => $result['status']));
        exit;
    }
    \PaymosCsCart\CredentialStore::clearState();
    throw new \RuntimeException('Paymos connection was denied or expired.');
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode(array('error' => $exception->getMessage()));
    exit;
}
