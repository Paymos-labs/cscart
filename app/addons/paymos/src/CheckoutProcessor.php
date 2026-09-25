<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;

final class CheckoutProcessor
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @param array<string, mixed> $processorParams
     * @return array<string, string>
     */
    public function start($orderId, array $orderInfo, array $processorParams)
    {
        $config = Config::fromProcessorParams($processorParams);
        // CS-Cart order `total` is always denominated in the primary currency, so
        // the invoice currency MUST be the order's primary currency — never
        // `secondary_currency` (display only), which would mis-label the amount.
        $amount = $this->formatAmount($this->field($orderInfo, 'total'));
        // …and CS-Cart never puts that currency on the order: there is no such column
        // in cscart_orders and fn_get_order_info() returns no `currency` key — only
        // `secondary_currency`, which is the display currency. The primary currency
        // lives in CART_PRIMARY_CURRENCY. Reading the absent field threw on EVERY
        // order, so no invoice could ever be created.
        $currency = strtoupper($this->field($orderInfo, 'currency'));
        if ($currency === '' && defined('CART_PRIMARY_CURRENCY')) {
            $currency = strtoupper((string) CART_PRIMARY_CURRENCY);
        }
        if ($currency === '') {
            throw new \RuntimeException('CS-Cart order currency is missing.');
        }

        // Always call the server — never short-circuit to a locally cached
        // payment_url. The server is idempotent on external_order_id, but it
        // answers a repeated id with the SAME invoice whatever became of it: an
        // expired or cancelled one included. So the id is reused only while the
        // snapshot matches and the invoice has not ended unpaid; if the server's
        // answer shows it did (or nobody started it before its deadline), the
        // suffix is bumped once and a fresh invoice is cut. The row keeps no
        // deadline: confirming a network moves expires_at on the server with no
        // webhook, so only the server's answer to the create call decides.
        // Every replacement first closes the old invoice (closeBeforeReplacing).
        $existing = $this->store->findByCsCartOrderId($orderId);
        $reused = is_array($existing)
            && $this->snapshotMatches($existing, $amount, $currency, $config)
            && !InvoiceRenewal::isRequired($existing);

        if ($reused) {
            $renewCount = isset($existing['renew_count']) ? (int) $existing['renew_count'] : 0;
        } else {
            if (is_array($existing)) {
                $this->closeBeforeReplacing($existing, $config);
            }
            $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        }

        $externalOrderId = 'cscart_' . (int) $orderId . '_' . $renewCount;
        $payload = $this->createPayload($orderInfo, $config, $amount, $currency, $externalOrderId);
        $response = $this->client($config)->invoices()->create($payload);

        if ($reused && InvoiceRenewal::isRequired($response)) {
            // The server's own answer for the old id: closed as it says, or
            // cancelled first if it is merely past its deadline.
            $this->closeBeforeReplacing(array(
                'paymos_invoice_id' => $this->responseField($response, array('invoice_id')),
                'environment' => $config->environment(),
                'status' => $this->responseField($response, array('status')),
            ), $config);
            $reused = false;
            $renewCount++;
            $externalOrderId = 'cscart_' . (int) $orderId . '_' . $renewCount;
            $payload = $this->createPayload($orderInfo, $config, $amount, $currency, $externalOrderId);
            $response = $this->client($config)->invoices()->create($payload);
        }

        $paymosInvoiceId = $this->responseField($response, array('invoice_id'));
        $paymentUrl = $this->responseField($response, array('payment_url'));
        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }

        $this->store->save(array(
            'cscart_order_id' => (int) $orderId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            'status' => $this->responseField($response, array('status')) ?: 'awaiting_client',
            'renew_count' => $renewCount,
        ));

        return array(
            'invoice_id' => $paymosInvoiceId,
            'payment_url' => $paymentUrl,
            'reused' => $reused ? '1' : '0',
        );
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @return array<string, mixed>
     */
    private function createPayload(array $orderInfo, Config $config, $amount, $currency, $externalOrderId)
    {
        $payload = array(
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
            'allow_multiple_payments' => true,
        );

        $clientId = $this->clientId($orderInfo);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, $amount, $currency, Config $config)
    {
        return AmountGuard::amountsEqual((string) $row['amount'], (string) $amount)
            && strtoupper((string) $row['currency']) === strtoupper((string) $currency)
            && (string) $row['project_id'] === $config->projectId()
            && (string) $row['environment'] === $config->environment()
            && trim((string) $row['payment_url']) !== '';
    }

    /**
     * The order's invoice is about to be replaced (the order changed, or the
     * invoice can no longer be paid). Cancel it on the server first, or the
     * buyer could pay both (BUG-166): the SDK cancels it, or confirms from the
     * server that it ended unpaid. Anything else — paid, still payable, 404,
     * no answer — keeps the old invoice and stops the checkout; the payment
     * script records the summary on the order for manual review.
     *
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing(array $row, Config $config)
    {
        $environment = (string) $row['environment'];
        $recorded = isset($row['status']) ? (string) $row['status'] : '';
        $result = (new InvoiceReplacement(function () use ($config, $environment) {
            return $this->client($config, $environment);
        }))->close((string) $row['paymos_invoice_id'], $recorded);

        if (!$result->isClosed()) {
            throw new InvoiceReplacementBlockedException($result);
        }

        // Record the final status before the new row exists, so the old
        // invoice's own webhook (invoice.cancelled after our cancel) finds a
        // final row and is ignored as stale.
        if ($result->status() !== '' && $result->status() !== $recorded) {
            $this->store->updateStatus((string) $row['paymos_invoice_id'], $result->status());
        }
    }

    /**
     * @param string|null $environment The environment the invoice lives in; the selected mode by default.
     */
    private function client(Config $config, $environment = null)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config, $environment);
        }

        return new Client($environment === null
            ? $config->clientConfig()
            : $config->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    private function clientId(array $orderInfo)
    {
        $userId = $this->field($orderInfo, 'user_id');
        return $userId !== '' && $userId !== '0' ? $userId : '';
    }

    private function formatAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $source
     */
    private function field(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function responseField(array $source, array $path)
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
