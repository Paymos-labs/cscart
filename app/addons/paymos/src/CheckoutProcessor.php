<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoicePresentation;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\StatusMapper;

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
        return $this->store->synchronized($orderId, function () use ($orderId, $orderInfo, $processorParams) {
            // Read the native total again after acquiring the same mutex as callbacks.
            if (function_exists('fn_get_order_info')) {
                $fresh = fn_get_order_info((int) $orderId);
                if (!is_array($fresh) || !$fresh) {
                    throw new \RuntimeException('CS-Cart order could not be reloaded.');
                }
                $orderInfo = $fresh;
            }
            return $this->startLocked($orderId, $orderInfo, $processorParams);
        });
    }

    private function startLocked($orderId, array $orderInfo, array $processorParams)
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
                'cscart_order_id' => (int) $orderId,
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

        $this->store->assertLocked($orderId);
        $this->store->save(array(
            'cscart_order_id' => (int) $orderId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            // Only a native callback may record an applied terminal decision.
            // Reopening checkout must leave a newly observed paid invoice retryable.
            'status' => $reused && (string) $existing['paymos_invoice_id'] === $paymosInvoiceId
                ? (string) $existing['status']
                : (StatusMapper::isFinalStatus($this->responseField($response, array('status')))
                    ? 'awaiting_client' : ($this->responseField($response, array('status')) ?: 'awaiting_client')),
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
        );

        $clientId = $this->clientId($orderInfo);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        $products = $this->extractProducts($orderInfo);
        if ($products !== null) {
            $payload['products'] = $products;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @return array<int, array<string, mixed>>|null
     */
    private function extractProducts(array $orderInfo): ?array
    {
        if (!isset($orderInfo['products']) || !is_array($orderInfo['products']) || count($orderInfo['products']) === 0) {
            return null;
        }

        $candidates = array();
        foreach ($orderInfo['products'] as $item) {
            if (!is_array($item)) {
                return null;
            }
            if (isset($item['type']) && is_string($item['type'])
                && in_array(strtolower(trim($item['type'])), array('shipping', 'discount', 'tax', 'fee', 'coupon', 'surcharge', 'note', 'gift_certificate'), true)) {
                continue;
            }

            if (array_key_exists('unit_amount', $item)) {
                $rawUnitAmount = $item['unit_amount'];
            } elseif (array_key_exists('price', $item)) {
                $rawUnitAmount = $item['price'];
            } elseif (array_key_exists('base_price', $item)) {
                $rawUnitAmount = $item['base_price'];
            } else {
                $rawUnitAmount = null;
            }

            $description = isset($item['description']) && is_string($item['description'])
                ? $item['description']
                : (isset($item['short_description']) && is_string($item['short_description'])
                    ? $item['short_description']
                    : null);

            $candidates[] = array(
                'title' => $this->productTitle($item),
                'description' => $description,
                'image_url' => $this->productImageUrl($item),
                'quantity' => array_key_exists('quantity', $item)
                    ? $item['quantity']
                    : (array_key_exists('amount', $item) ? $item['amount'] : null),
                'unit_amount' => self::formatUnitAmount($rawUnitAmount),
            );
        }

        return InvoicePresentation::normalizeProducts($candidates);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function productTitle(array $item): ?string
    {
        $base = array_key_exists('title', $item)
            ? $item['title']
            : (array_key_exists('product', $item) ? $item['product'] : null);
        if (!is_string($base)) {
            return null;
        }

        if (!isset($item['product_options']) || !is_array($item['product_options']) || count($item['product_options']) === 0) {
            return $base;
        }

        $parts = array();
        foreach ($item['product_options'] as $option) {
            if (!is_array($option)) {
                continue;
            }
            $name = isset($option['option_name']) && is_scalar($option['option_name']) ? trim((string) $option['option_name']) : '';
            $value = '';
            if (isset($option['variant_name']) && is_scalar($option['variant_name']) && trim((string) $option['variant_name']) !== '') {
                $value = trim((string) $option['variant_name']);
            } elseif (isset($option['value']) && is_scalar($option['value']) && trim((string) $option['value']) !== '') {
                $value = trim((string) $option['value']);
            }
            if ($value === '') {
                continue;
            }
            $parts[] = $name !== '' ? ($name . ': ' . $value) : $value;
        }

        if (count($parts) === 0) {
            return $base;
        }

        $withOptions = $base . ' (' . implode(', ', $parts) . ')';
        $chars = preg_split('//u', $withOptions, 201, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars) && count($chars) <= 200) {
            return $withOptions;
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function productImageUrl(array $item): ?string
    {
        if (isset($item['image_url']) && is_string($item['image_url'])) {
            return $item['image_url'];
        }

        if (isset($item['main_pair']) && is_array($item['main_pair'])) {
            $fromPair = self::imageUrlFromPair($item['main_pair']);
            if ($fromPair !== null) {
                return $fromPair;
            }
        }

        if (function_exists('fn_get_image_pairs') && isset($item['product_id']) && is_numeric($item['product_id']) && (int) $item['product_id'] > 0) {
            try {
                $pair = fn_get_image_pairs((int) $item['product_id'], 'product', 'M', true, true);
                if (is_array($pair)) {
                    return self::imageUrlFromPair($pair);
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $pair
     */
    private static function imageUrlFromPair(array $pair): ?string
    {
        $candidates = array(
            $pair['detailed']['https_image_path'] ?? null,
            $pair['icon']['https_image_path'] ?? null,
            $pair['https_image_path'] ?? null,
            $pair['detailed']['image_path'] ?? null,
            $pair['icon']['image_path'] ?? null,
            $pair['image_path'] ?? null,
        );
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param mixed $raw
     */
    private static function formatUnitAmount($raw): ?string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }
        if (is_float($raw)) {
            if (!is_finite($raw)) {
                return null;
            }
            if ($raw < 0) {
                return '-1';
            }
            $formatted = (string) $raw;
            if (stripos($formatted, 'e') !== false) {
                $formatted = rtrim(rtrim(sprintf('%.18F', $raw), '0'), '.');
            }
            return $formatted;
        }
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/\A(0|[1-9][0-9]*)\.([0-9]+)\z/', $trimmed, $m) === 1) {
            $frac = rtrim($m[2], '0');
            if (strlen($m[2]) > 2 && strlen($frac) <= 2) {
                return $m[1] . '.' . str_pad($frac, 2, '0');
            }
        }

        return $trimmed;
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
            $this->store->assertLocked($row['cscart_order_id']);
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
        $normalized = AmountGuard::normalizeDecimal($amount);
        if ($normalized === null || $normalized === '0' || $normalized[0] === '-') {
            throw new \RuntimeException('CS-Cart order amount must be a positive decimal.');
        }
        return $normalized;
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
