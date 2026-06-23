<?php

declare(strict_types=1);

defined('BOOTSTRAP') or die('Access denied');

require_once __DIR__ . '/../src/Autoloader.php';
\PaymosCsCart\Autoloader::register();

if (defined('PAYMENT_NOTIFICATION')) {
    $callbackMode = isset($mode) ? (string) $mode : (isset($_REQUEST['mode']) ? (string) $_REQUEST['mode'] : '');
    $rawBody = file_get_contents('php://input');
    $signature = isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) ? (string) $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] : '';

    $processorParams = array();
    if (isset($processor_data['processor_params']) && is_array($processor_data['processor_params'])) {
        $processorParams = $processor_data['processor_params'];
    } else {
        // A Paymos webhook POSTs the invoice JSON with no order_id query param, so
        // CS-Cart may not pre-populate $processor_data. Resolve the order from the
        // body's external_order_id via the invoice snapshot, then load its
        // processor params so admin status overrides apply on the webhook path.
        $cscartOrderId = 0;
        if (isset($_REQUEST['order_id'])) {
            $cscartOrderId = (int) $_REQUEST['order_id'];
        } else {
            $decoded = json_decode($rawBody, true);
            $externalOrderId = is_array($decoded) && isset($decoded['data']['order']['external_id'])
                ? (string) $decoded['data']['order']['external_id']
                : '';
            if ($externalOrderId !== '') {
                $snapshot = (new \PaymosCsCart\InvoiceStore())->findByExternalOrderId($externalOrderId);
                if (is_array($snapshot) && isset($snapshot['cscart_order_id'])) {
                    $cscartOrderId = (int) $snapshot['cscart_order_id'];
                }
            }
        }

        if ($cscartOrderId > 0 && function_exists('fn_get_order_info') && function_exists('fn_get_processor_data')) {
            $orderInfo = fn_get_order_info($cscartOrderId);
            if (is_array($orderInfo) && isset($orderInfo['payment_id'])) {
                $loadedProcessor = fn_get_processor_data((int) $orderInfo['payment_id']);
                if (isset($loadedProcessor['processor_params']) && is_array($loadedProcessor['processor_params'])) {
                    $processorParams = $loadedProcessor['processor_params'];
                }
            }
        }
    }

    $result = (new \PaymosCsCart\WebhookProcessor(
        new \PaymosCsCart\CsCartAdapter(),
        new \PaymosCsCart\InvoiceStore(),
        new \PaymosCsCart\EventStore()
    ))->handleMode($callbackMode, $rawBody, $signature, $processorParams);

    http_response_code($result->statusCode());
    echo $result->body();
    exit;
}

try {
    $processorParams = isset($processor_data['processor_params']) && is_array($processor_data['processor_params'])
        ? $processor_data['processor_params']
        : array();

    $result = (new \PaymosCsCart\CheckoutProcessor(new \PaymosCsCart\InvoiceStore()))
        ->start($order_id, is_array($order_info) ? $order_info : array(), $processorParams);

    $config = \PaymosCsCart\Config::fromProcessorParams($processorParams);
    $pp_response = array(
        'order_status' => $config->status('pending'),
        'reason_text' => 'Awaiting Paymos payment. Invoice: ' . $result['invoice_id'],
        'transaction_id' => $result['invoice_id'],
    );

    if (function_exists('fn_update_order_payment_info')) {
        fn_update_order_payment_info((int) $order_id, $pp_response);
    }

    fn_redirect($result['payment_url'], true);
} catch (\Throwable $e) {
    if (function_exists('fn_set_notification')) {
        fn_set_notification('E', __('error'), 'Paymos payment error: ' . $e->getMessage());
    }
    if (function_exists('fn_redirect')) {
        fn_redirect('checkout.checkout');
    }
}

exit;
