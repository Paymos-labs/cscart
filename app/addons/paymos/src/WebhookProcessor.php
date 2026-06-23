<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

final class WebhookProcessor
{
    /** @var CsCartAdapterInterface */
    private $cscart;

    /** @var InvoiceStoreInterface */
    private $invoiceStore;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        CsCartAdapterInterface $cscart,
        InvoiceStoreInterface $invoiceStore,
        EventStoreInterface $eventStore,
        callable $clientFactory = null
    ) {
        $this->cscart = $cscart;
        $this->invoiceStore = $invoiceStore;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $processorParams
     */
    public function handleMode($mode, $rawBody, $signatureHeader, array $processorParams, $now = null)
    {
        if ((string) $mode !== 'webhook') {
            return new CallbackResult(400, 'Invalid callback mode');
        }

        return $this->handle($rawBody, $signatureHeader, $processorParams, $now);
    }

    /**
     * @param array<string, mixed> $processorParams
     */
    public function handle($rawBody, $signatureHeader, array $processorParams, $now = null)
    {
        try {
            $config = Config::fromProcessorParams($processorParams);
            $verified = (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent();
                return new CallbackResult(200, 'OK');
            }

            $this->assertPayloadEnvironment($event, $environment);
            $this->applyVerifiedEvent($event, $environment, $processorParams, true);
            $this->commitEvent();

            return new CallbackResult(200, 'OK');
        } catch (DuplicateEventException $e) {
            $this->debugLog($processorParams, 'Paymos duplicate webhook ignored.', array('duplicate' => true));
            return new CallbackResult(200, 'OK', true);
        } catch (SignatureMismatchException $e) {
            return new CallbackResult(401, 'Bad signature');
        } catch (TimestampSkewException $e) {
            return new CallbackResult(401, 'Bad timestamp');
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent();
            $this->cscart->log('Paymos CS-Cart configuration error.', array('error' => $e->getMessage()));
            return new CallbackResult(500, 'Configuration error');
        } catch (\RuntimeException $e) {
            $this->releaseEvent();
            $this->cscart->log('Paymos CS-Cart webhook processing failed.', array('error' => $e->getMessage()));
            return new CallbackResult(400, 'Processing failed');
        } catch (\Throwable $e) {
            // A PHP Error (e.g. a TypeError in a third-party order hook) during
            // mutation must still release the in-flight dedup lock, otherwise the
            // event is durably marked seen and the order is never retried.
            $this->releaseEvent();
            $this->cscart->log('Paymos CS-Cart webhook processing failed.', array('error' => $e->getMessage()));
            return new CallbackResult(400, 'Processing failed');
        }
    }

    /**
     * @param array<string, mixed> $processorParams
     */
    private function applyVerifiedEvent(WebhookEvent $event, $environment, array $processorParams, $reverseVerify)
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing external order id.');
        }

        $row = $this->invoiceStore->findByExternalOrderId($externalOrderId);
        if (!is_array($row)) {
            throw new \RuntimeException('Paymos CS-Cart invoice snapshot was not found.');
        }

        return $this->applyEventToCsCart($event, $environment, $row, $processorParams, $reverseVerify);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $processorParams
     */
    private function applyEventToCsCart(WebhookEvent $event, $environment, array $row, array $processorParams, $reverseVerify)
    {
        $config = Config::fromProcessorParams($processorParams);
        $this->assertRowMatchesEvent($row, $event, $environment);

        if ($reverseVerify && $this->requiresReverseVerify($event)) {
            $result = (new InvoiceReverseVerifier($this->client($environment, $processorParams)))->verify($event, array(
                'project_id' => (string) $row['project_id'],
                'external_order_id' => (string) $row['external_order_id'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
            ));

            if (!$result->isVerified()) {
                throw new \RuntimeException('Paymos reverse verification failed: ' . $result->reason());
            }
        }

        $action = StatusMapper::invoiceAction($event->type(), $event->status());

        if ($action === StatusMapper::ACTION_IGNORE) {
            return false;
        }

        $order = $this->cscart->getOrder((int) $row['cscart_order_id']);
        if (count($order) === 0) {
            throw new \RuntimeException('CS-Cart order for Paymos invoice snapshot was not found.');
        }

        // Roll-back guard: out-of-order webhook delivery (a stale confirming, a
        // late cancelled/expired/underpaid after paid) must never downgrade an
        // already-paid order. Reverse-verify covers forgery, not delivery order.
        if ($this->wouldRollBackPaidOrder($config, $order, $action)) {
            if ($config->debugLogging()) {
                $this->cscart->log('Paymos ignored a stale invoice status after payment completed. Invoice: ' . $event->invoiceId());
            }
            return false;
        }

        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
            // Order total is in the primary currency; compare against the order's
            // primary currency, consistent with how the invoice was created.
            $currentAmount = $this->formatAmount($this->scalar($order, 'total', $row['amount']));
            $currentCurrency = strtoupper($this->scalar($order, 'currency', $row['currency']));
            if (!AmountGuard::isSafeToComplete(
                $row['amount'],
                $row['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            )) {
                throw new \RuntimeException(AmountGuard::mismatchSummary(
                    $row['amount'],
                    $row['currency'],
                    $currentAmount,
                    $currentCurrency,
                    $event->orderAmount(),
                    $event->orderCurrency()
                ));
            }
        }

        // Persist the snapshot status only after the roll-back and amount guards
        // have passed, so a rejected stale event never overwrites the snapshot.
        $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());

        $response = array(
            'order_status' => $this->statusForAction($config, $action),
            'reason_text' => $this->commentForAction($action, $event),
            'transaction_id' => $this->transactionId($event),
        );

        $this->cscart->finishPayment((int) $row['cscart_order_id'], $response);

        return $action === StatusMapper::ACTION_PAYMENT_COMPLETE;
    }

    /**
     * On-chain tx hash from data.payment.transfers[] (latest confirmed), falling
     * back to the invoice id when the payload carries no transfers (sandbox or
     * simulated payment).
     */
    private function transactionId(WebhookEvent $event)
    {
        $payload = $event->toArray();
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $transfers = null;
        if (isset($data['payment']['transfers']) && is_array($data['payment']['transfers'])) {
            $transfers = $data['payment']['transfers'];
        } elseif (isset($data['transfers']) && is_array($data['transfers'])) {
            $transfers = $data['transfers'];
        }

        if ($transfers !== null) {
            $confirmed = '';
            $latest = '';
            foreach ($transfers as $transfer) {
                if (!is_array($transfer) || !isset($transfer['tx_hash']) || !is_string($transfer['tx_hash']) || $transfer['tx_hash'] === '') {
                    continue;
                }
                $latest = $transfer['tx_hash'];
                $status = isset($transfer['status']) && is_string($transfer['status']) ? strtolower($transfer['status']) : '';
                if ($status === 'confirmed') {
                    $confirmed = $transfer['tx_hash'];
                }
            }
            if ($confirmed !== '') {
                return $confirmed;
            }
            if ($latest !== '') {
                return $latest;
            }
        }

        return $event->invoiceId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRowMatchesEvent(array $row, WebhookEvent $event, $environment)
    {
        if ((string) $row['environment'] !== (string) $environment) {
            throw new \RuntimeException('Paymos event environment does not match CS-Cart invoice snapshot.');
        }
        if ((string) $row['project_id'] !== '' && $event->projectId() !== '' && (string) $row['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match CS-Cart invoice snapshot.');
        }
        if ((string) $row['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $row['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match CS-Cart invoice snapshot.');
        }
        if ((string) $row['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $row['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match CS-Cart invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * @param array<string, mixed> $processorParams
     */
    private function client($environment, array $processorParams)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client(Config::fromProcessorParams($processorParams)->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $order
     */
    private function wouldRollBackPaidOrder(Config $config, array $order, $action)
    {
        $currentStatus = $this->scalar($order, 'status', '');
        if ($currentStatus === '') {
            return false;
        }

        // Protect any at-or-past-paid order: the configured paid status plus the
        // CS-Cart built-in Complete ('C') status that merchants commonly advance
        // paid orders to. A late downgrade must not undo either.
        $protectedStatuses = array($config->status('paid'), 'C');
        if (!in_array($currentStatus, $protectedStatuses, true)) {
            return false;
        }

        return in_array($action, array(
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * Diagnostic logging gated on the admin debug_logging toggle. Operational
     * failures (config/processing errors) log unconditionally; routine
     * diagnostics (duplicates, roll-back skips) only when debug is enabled.
     *
     * @param array<string, mixed> $processorParams
     * @param array<string, mixed> $context
     */
    private function debugLog(array $processorParams, $message, array $context = array())
    {
        try {
            if (!Config::fromProcessorParams($processorParams)->debugLogging()) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $this->cscart->log($message, $context);
    }

    private function statusForAction(Config $config, $action)
    {
        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
                return $config->status('confirming');
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $config->status('paid');
            case StatusMapper::ACTION_FAIL_ORDER:
                return $config->status('failed');
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $config->status('cancelled');
            case StatusMapper::ACTION_AWAITING_PAYMENT:
            default:
                return $config->status('pending');
        }
    }

    private function commentForAction($action, WebhookEvent $event)
    {
        $invoice = $event->invoiceId();
        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'Paymos payment is confirming. Invoice: ' . $invoice;
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return 'Paymos payment completed. Invoice: ' . $invoice;
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'Paymos payment failed or remained underpaid. Invoice: ' . $invoice;
            case StatusMapper::ACTION_CANCEL_ORDER:
                return 'Paymos invoice expired or was cancelled. Invoice: ' . $invoice;
            default:
                return 'Awaiting Paymos payment. Invoice: ' . $invoice;
        }
    }

    private function formatAmount($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalar(array $source, $key, $fallback)
    {
        return isset($source[$key]) && is_scalar($source[$key]) && trim((string) $source[$key]) !== ''
            ? trim((string) $source[$key])
            : (string) $fallback;
    }

    private function commitEvent()
    {
        if (method_exists($this->eventStore, 'commit')) {
            $this->eventStore->commit();
        }
    }

    private function releaseEvent()
    {
        if (method_exists($this->eventStore, 'release')) {
            $this->eventStore->release();
        }
    }
}
