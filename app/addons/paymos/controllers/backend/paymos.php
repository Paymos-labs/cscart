<?php

declare(strict_types=1);

defined('BOOTSTRAP') or die('Access denied');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!isset($mode) || $mode !== 'refunds')) {
    return array(CONTROLLER_STATUS_DENIED);
}

require_once dirname(__DIR__, 2) . '/src/Autoloader.php';
\PaymosCsCart\Autoloader::register();

if (in_array($mode, array('refunds', 'refund', 'refund_reconcile'), true)) {
    try {
        $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $orderId = isset($request['order_id']) && is_scalar($request['order_id']) ? (int) $request['order_id'] : 0;
        $permission = function_exists('fn_check_permissions')
            && fn_check_permissions('orders', 'update', 'admin', 'POST', array('order_id' => $orderId));
        $securityHash = function_exists('fn_generate_security_hash') ? (string) fn_generate_security_hash() : '';
        \PaymosCsCart\RefundAdapter::assertAccess(is_array($auth) ? $auth : array(), $_SERVER['REQUEST_METHOD'], $request,
            (bool) $permission, $securityHash);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \PaymosCsCart\RefundAdapter::assertRequest($request);
        }
        if ($orderId < 1) {
            throw new \RuntimeException('refund_order_missing');
        }
        \PaymosCsCart\RefundSchema::ensure();
        $refundStore = new \PaymosCsCart\RefundIntentStore();
        $refundAdapter = new \PaymosCsCart\RefundAdapter($refundStore);
        // Company and payment mapping are checked before either action, including recovery.
        $refundAdapter->assertOrderAccess($orderId);
        if ($mode === 'refund') {
            $refundMode = isset($request['refund_mode']) && is_string($request['refund_mode']) ? $request['refund_mode'] : '';
            $token = isset($request['request_token']) && is_string($request['request_token']) ? $request['request_token'] : '';
            $refundAdapter->submit($orderId, $refundMode, $token);
            return array(CONTROLLER_STATUS_REDIRECT, 'paymos.refunds?order_id=' . $orderId);
        }
        if ($mode === 'refund_reconcile') {
            $operationId = isset($request['operation_id']) && is_string($request['operation_id']) ? $request['operation_id'] : '';
            if ($refundStore->getIntent($operationId)->paymentKey() !== 'cscart:' . $orderId) {
                throw new \RuntimeException('refund_order_scope_mismatch');
            }
            $refundAdapter->reconcile($operationId);
            return array(CONTROLLER_STATUS_REDIRECT, 'paymos.refunds?order_id=' . $orderId);
        }
        \Tygh\Tygh::$app['view']->assign('paymos_refund_security_hash', $securityHash);
        \Tygh\Tygh::$app['view']->assign('paymos_refund_panel', $refundAdapter->panel($orderId));
        return array(CONTROLLER_STATUS_OK);
    } catch (\Throwable $exception) {
        if (function_exists('fn_set_notification')) {
            $refundNotices = array(
                'refunds' => 'paymos.refunds_open_failed',
                'refund' => 'paymos.refund_request_failed',
                'refund_reconcile' => 'paymos.refund_check_failed',
            );
            fn_set_notification('E', __('error'), $exception->getMessage() === 'refund_reconnect_required'
                ? __('paymos.refund_reconnect_required')
                : __($refundNotices[$mode]));
        }
        error_log('[Paymos CS-Cart] ' . $exception->getMessage());
        return array(CONTROLLER_STATUS_DENIED);
    }
}

header('Content-Type: application/json');
try {
    $sourceUrl = rtrim((string) fn_url('', 'C', 'https'), '/');
    if ($sourceUrl === '' || stripos($sourceUrl, 'https://') !== 0) {
        throw new \RuntimeException('CS-Cart storefront URL must use HTTPS.');
    }
    $client = new \Paymos\Connect\DeviceConnectClient('https://app.paymos.io');
    if ($mode === 'connect_start') {
        // The admin page posts its own URL so approval can return the merchant to it.
        // Paymos drops it unless it shares an origin with the storefront URL above.
        $returnUrl = isset($_POST['paymos_return_url']) ? (string) $_POST['paymos_return_url'] : '';
        $state = $client->start('cscart', $sourceUrl, $returnUrl);
        \PaymosCsCart\CredentialStore::startAttempt($state);
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
    $deviceCode = (string) $state['device_code'];
    $result = $client->poll($deviceCode);
    if ($result['status'] === 'connected') {
        if ($result['plugin'] !== 'cscart' || rtrim((string) $result['source_url'], '/') !== $sourceUrl) {
            throw new \RuntimeException('Paymos connection response does not match this CS-Cart storefront.');
        }
        // Applied only if no newer attempt replaced this one during the poll.
        \PaymosCsCart\CredentialStore::whileCurrentAttempt($deviceCode, static function () use ($result) {
            \PaymosCsCart\CredentialStore::saveCredentials($result['credentials']);
            \PaymosCsCart\CredentialStore::clearState();
        });
        \PaymosCsCart\Config::resetForTests();
        echo json_encode(array('status' => 'connected'));
        exit;
    }
    if (in_array($result['status'], array('authorization_pending', 'slow_down'), true)) {
        echo json_encode(array('status' => $result['status']));
        exit;
    }
    \PaymosCsCart\CredentialStore::whileCurrentAttempt($deviceCode, static function () {
        \PaymosCsCart\CredentialStore::clearState();
    });
    throw new \RuntimeException('Paymos connection was denied or expired.');
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode(array('error' => $exception->getMessage()));
    exit;
}
