<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private $store;
    private $nativeLoader;
    private $clientFactory;

    public function __construct(RefundIntentStore $store, ?callable $nativeLoader = null, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->nativeLoader = $nativeLoader ?: function ($orderId) use ($store) {
            return RefundNative::load($orderId, $store->database());
        };
        $this->clientFactory = $clientFactory ?: static function ($environment, array $params) {
            return new Client(Config::fromProcessorParams($params)->clientConfigForEnvironment($environment));
        };
    }

    public static function assertAccess(array $auth, string $method, array $request, bool $permission, string $securityHash): void
    {
        if (empty($auth['user_id']) || ($auth['user_type'] ?? '') !== 'A' || !$permission
            || !in_array($method, array('GET', 'POST'), true)
            || ($method === 'POST' && ($securityHash === '' || !isset($request['security_hash'])
                || !is_string($request['security_hash']) || !hash_equals($securityHash, $request['security_hash'])))) {
            throw new \RuntimeException('refund_access_denied');
        }
    }

    public static function assertRequest(array $request): void
    {
        foreach (array('amount', 'currency', 'network', 'destination_address', 'address', 'parts',
            'partial', 'line_items', 'restock', 'notify') as $field) {
            if (array_key_exists($field, $request)) {
                throw new \RuntimeException('refund_partial_or_override_unsupported');
            }
        }
        foreach (array_keys($request) as $field) {
            if (!in_array($field, array('dispatch', 'security_hash', 'order_id', 'refund_mode', 'request_token', 'operation_id'), true)) {
                throw new \RuntimeException('refund_input_unsupported');
            }
        }
    }

    public function submit(int $orderId, string $mode, string $token): RefundIntent
    {
        if ($orderId < 1 || !in_array($mode, array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)
            || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $token)) {
            throw new \RuntimeException('refund_invalid_request');
        }
        return $this->store->withPaymentLock('cscart:' . $orderId, function () use ($orderId, $mode, $token) {
            $this->store->assertNativeConnection();
            $existing = $this->store->findByRequestToken($this->store->installationId(), $token);
            $native = $this->native($orderId);
            $this->assertCompany($native);
            if ($existing !== null) {
                if ($existing->paymentKey() !== 'cscart:' . $orderId || $existing->mode() !== $mode) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $intent = $existing;
            } else {
                $this->store->assertNewActionAllowed('cscart:' . $orderId);
                $snapshot = $native['snapshot'];
                if (!is_array($snapshot)) {
                    throw new \RuntimeException('refund_invoice_mapping_ambiguous');
                }
                $draft = $mode === RefundStates::FULL ? $this->draft($native) : null;
                $intent = new RefundIntent(array(
                    'operation_id' => RefundIntentStore::uuid(), 'installation_id' => $this->store->installationId(),
                    'request_token' => $token, 'platform' => 'cscart', 'native_payment_key' => 'cscart:' . $orderId,
                    'invoice_id' => $snapshot['paymos_invoice_id'], 'project_id' => $snapshot['project_id'],
                    'environment' => $snapshot['environment'], 'mode' => $mode, 'full_draft' => $draft,
                    'native_identity' => $this->identity($native),
                ));
                $reason = $this->newSubmissionReason($intent, false);
                if ($reason !== null) {
                    throw new \RuntimeException($reason);
                }
                $intent = $this->store->createIntent($intent);
            }
            $this->coordinator($intent)->submit($intent->operationId());
            return $this->store->getIntent($intent->operationId());
        });
    }

    public function preflight(RefundIntent $intent): ?string
    {
        return $this->newSubmissionReason($intent, true);
    }

    private function newSubmissionReason(RefundIntent $intent, bool $readSummary): ?string
    {
        try {
            $native = $this->native($this->orderId($intent));
            $reason = $this->mappingReason($intent, $native);
            if ($reason !== null) {
                return $reason;
            }
            $connection = $native['connection'][$intent->environment()] ?? array();
            if (!CredentialSet::hasCapability($connection, 'invoice_refunds_v1')
                || ($connection['refund_contract_version'] ?? null) !== 1) {
                return 'refund_reconnect_required';
            }
            if (!CredentialSet::refundsEnabled($connection)) {
                return 'refund_submissions_disabled';
            }
            if ($intent->mode() === RefundStates::FULL && $intent->fullDraft() !== $this->draft($native)) {
                return 'refund_native_draft_changed';
            }
            foreach ($this->store->forPayment($intent->paymentKey()) as $previous) {
                if ($previous->mode() === RefundStates::FULL && $previous->toArray()['projection'] !== null) {
                    return 'refund_native_already_refunded';
                }
            }
            if ($readSummary) {
                $invoice = $this->readInvoice($intent->invoiceId(), $intent->environment(), $native);
                $summary = $invoice['refund_summary'] ?? array();
                $field = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
                if (($summary[$field] ?? false) !== true) {
                    return 'refund_server_action_unavailable';
                }
            }
            return null;
        } catch (\Throwable $error) {
            return 'refund_native_preflight_unavailable';
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $native = $this->native($this->orderId($intent));
        $reason = $this->mappingReason($intent, $native);
        if ($reason !== null || ($intent->mode() === RefundStates::FULL && $intent->fullDraft() !== $this->draft($native))) {
            return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null,
                $reason ?: 'refund_native_draft_changed');
        }
        $row = $this->store->projectionRow($operationId);
        if ($row === null) {
            foreach ($this->store->forPayment($intent->paymentKey()) as $previous) {
                if ($previous->operationId() !== $operationId && $previous->mode() === RefundStates::FULL
                    && $previous->toArray()['projection'] !== null) {
                    return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null,
                        'refund_native_already_refunded');
                }
            }
            return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode());
        }
        $expected = $this->result($intent, $intent->observation());
        $result = NativeProjectionResult::fromStorage(json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR));
        if ((int) $row['order_id'] !== $this->orderId($intent) || $row['invoice_id'] !== $intent->invoiceId()
            || $row['withdrawal_id'] !== $intent->withdrawalId() || $row['mode'] !== $intent->mode()
            || $result->toArray() !== $expected->toArray()) {
            return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null,
                'refund_native_marker_conflict');
        }
        return $result;
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $inspection = $this->inspect($intent->operationId());
        if ($inspection->kind() !== NativeProjectionResult::ABSENT) {
            return $inspection;
        }
        $pin = $intent->observation();
        if ($refund->status() !== 'completed' || $pin === null || $pin->toArray() !== $refund->toArray()) {
            throw new \RuntimeException('refund_completed_evidence_required');
        }
        $result = $this->result($intent, $refund);
        $this->store->saveNativeProjection($intent, $this->orderId($intent), $result);
        return $result;
    }

    /** Called only after the SDK verified signature and environment. Event data is a read signal. */
    public function handleVerifiedWebhook(WebhookEvent $event, string $environment): bool
    {
        if (strpos($event->type(), 'withdrawal.') !== 0) {
            return false;
        }
        $data = $event->toArray()['data'] ?? array();
        if (empty($data['invoice_id']) && empty($data['mode'])) {
            return false;
        }
        $intent = isset($data['external_order_id']) && is_string($data['external_order_id'])
            ? $this->store->findByExternalId($data['external_order_id']) : null;
        if ($intent === null) {
            $this->reconcileUnbound($data, $environment);
            return true;
        }
        if (($data['invoice_id'] ?? null) !== $intent->invoiceId()
            || (isset($data['project_id']) && $data['project_id'] !== $intent->projectId())
            || ($data['mode'] ?? null) !== $intent->mode() || $environment !== $intent->environment()
            || ($data['is_test'] ?? null) !== ($environment === 'sandbox')
            || !isset($data['withdrawal_id']) || !is_string($data['withdrawal_id']) || $data['withdrawal_id'] === ''
            || ($intent->withdrawalId() !== null && $data['withdrawal_id'] !== $intent->withdrawalId())) {
            throw new \RuntimeException('refund_callback_scope_mismatch');
        }
        $this->reconcile($intent->operationId());
        return true;
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) {
            $this->store->assertNativeConnection();
            $action = $this->coordinator($intent)->reconcile($intent->operationId());
            $this->store->schedule($intent->operationId());
            return $action;
        });
    }

    public function reconcileDue(\DateTimeImmutable $now, ?string $cursor = null): array
    {
        $page = $this->store->listDue($now, 50, $cursor);
        $results = array();
        foreach ($page->items() as $intent) {
            try {
                $results[$intent->operationId()] = $this->reconcile($intent->operationId());
            } catch (\Throwable $error) {
                $results[$intent->operationId()] = 'reconcile_unavailable';
                try {
                    $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) {
                        $this->store->schedule($intent->operationId());
                    });
                } catch (\Throwable $unavailable) {
                    // Database recovery is required before the next sweep can progress.
                }
            }
        }
        foreach ($this->store->unboundDue($now, 50) as $row) {
            try {
                $snapshot = VerifiedRefund::fromStorage(json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR));
                $this->reconcileUnbound($snapshot->toArray(), $snapshot->environment());
            } catch (\Throwable $error) {
                $results[$row['withdrawal_id']] = 'reconcile_unavailable';
                try {
                    $this->store->withPaymentLock('cscart:' . (int) $row['order_id'], function () use ($row) {
                        $this->store->deferUnbound($row);
                    });
                } catch (\Throwable $unavailable) {
                    // Keep the original verified observation when reads or storage are unavailable.
                }
            }
        }
        return array('operations' => $results, 'next_cursor' => $page->nextCursor());
    }

    public function panel(int $orderId): array
    {
        $native = $this->native($orderId);
        $this->assertCompany($native);
        $processor = $native['processor'];
        if (($processor['processor_script'] ?? '') !== 'paymos.php' || ($processor['addon'] ?? '') !== 'paymos') {
            throw new \RuntimeException('refund_payment_mapping_mismatch');
        }
        $rows = array();
        foreach ($this->store->forPayment('cscart:' . $orderId) as $intent) {
            $data = $intent->toArray();
            $rows[] = array_intersect_key($data, array_flip(array('operation_id', 'mode', 'withdrawal_id', 'cash_state',
                'projection_state', 'review_reason', 'full_draft', 'observation')));
        }
        $summary = null;
        try {
            $snapshot = $native['snapshot'];
            if (is_array($snapshot)) {
                $invoice = $this->readInvoice($snapshot['paymos_invoice_id'], $snapshot['environment'], $native);
                $summary = $invoice['refund_summary'] ?? null;
            }
        } catch (\Throwable $error) {
            // Keep history and recovery controls available when previews cannot be read.
        }
        $blocked = false;
        foreach ($rows as $row) {
            if (!in_array($row['cash_state'], array('completed', 'failed', 'cancelled'), true)
                || $row['projection_state'] === RefundStates::NATIVE_REVIEW
                || ($row['cash_state'] === 'completed' && ($row['mode'] === 'full' || $row['projection_state'] !== 'applied'))) {
                $blocked = true;
            }
        }
        $snapshot = $native['snapshot'];
        $connection = is_array($snapshot) ? ($native['connection'][$snapshot['environment']] ?? array()) : array();
        $enabled = CredentialSet::hasCapability($connection, 'invoice_refunds_v1')
            && ($connection['refund_contract_version'] ?? null) === 1 && CredentialSet::refundsEnabled($connection);
        try {
            if (!is_array($snapshot)) {
                throw new \RuntimeException('refund_invoice_mapping_ambiguous');
            }
            $preview = new RefundIntent(array('operation_id' => RefundIntentStore::uuid(), 'installation_id' => $this->store->installationId(),
                'request_token' => RefundIntentStore::uuid(), 'platform' => 'cscart', 'native_payment_key' => 'cscart:' . $orderId,
                'invoice_id' => $snapshot['paymos_invoice_id'], 'project_id' => $snapshot['project_id'], 'environment' => $snapshot['environment'],
                'mode' => RefundStates::OVERPAYMENT, 'native_identity' => $this->identity($native)));
            $enabled = $enabled && $this->mappingReason($preview, $native) === null;
            $this->draft($native);
        } catch (\Throwable $error) {
            $enabled = false;
        }
        $unbound = $this->store->unboundForPayment('cscart:' . $orderId);
        foreach ($unbound as &$row) {
            $row['observation'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['payload'], $row['result']);
        }
        unset($row);
        try {
            $this->store->withPaymentLock('cscart:' . $orderId, function () use ($orderId) {
                $this->store->assertNewActionAllowed('cscart:' . $orderId);
            });
        } catch (\Throwable $error) {
            $blocked = true;
        }
        return array('order_id' => $orderId, 'request_token' => RefundIntentStore::uuid(), 'operations' => $rows, 'unbound' => $unbound,
            'summary' => $summary,
            'can_full' => !$blocked && $enabled && ($summary['can_refund_full'] ?? false) === true,
            'can_overpayment' => !$blocked && $enabled && ($summary['can_refund_overpayment'] ?? false) === true);
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        $native = $this->native($this->orderId($intent));
        $client = call_user_func($this->clientFactory, $intent->environment(), $native['params']);
        return new RefundCoordinator($client, $this->store, $this);
    }

    public function assertOrderAccess(int $orderId): void
    {
        $native = $this->native($orderId);
        $this->assertCompany($native);
        if (($native['processor']['processor_script'] ?? '') !== 'paymos.php' || ($native['processor']['addon'] ?? '') !== 'paymos') {
            throw new \RuntimeException('refund_payment_mapping_mismatch');
        }
    }

    private function mappingReason(RefundIntent $intent, array $native): ?string
    {
        $order = $native['order'];
        $snapshot = $native['snapshot'];
        $connection = $native['connection'][$intent->environment()] ?? array();
        $processor = $native['processor'];
        if (!preg_match('/^4\.(19|20)\.[0-9]+(?:[.-].*)?$/D', $native['version'])) {
            return 'refund_native_version_unsupported';
        }
        if (!is_array($snapshot) || ($snapshot['paymos_invoice_id'] ?? '') !== $intent->invoiceId()
            || ($snapshot['project_id'] ?? '') !== $intent->projectId() || ($snapshot['environment'] ?? '') !== $intent->environment()
            || ($connection['project_id'] ?? '') !== $intent->projectId()
            || ($processor['processor_script'] ?? '') !== 'paymos.php' || ($processor['addon'] ?? '') !== 'paymos'
            || (int) ($snapshot['cscart_order_id'] ?? 0) !== $this->orderId($intent)
            || (int) ($order['order_id'] ?? 0) !== $this->orderId($intent)
            || ($intent->toArray()['native_identity'] ?? null) !== $this->identity($native)) {
            return 'refund_payment_mapping_mismatch';
        }
        $paid = $native['params']['paid_status'] ?? 'P';
        if (!in_array($snapshot['status'], array('paid', 'paid_over'), true) || !in_array($order['status'] ?? '', array($paid, 'C'), true)
            || $native['manual_refund'] || !is_string($order['total'])
            || !AmountGuard::amountsEqual($order['total'], $snapshot['amount']) || $native['currency'] !== $snapshot['currency']) {
            return 'refund_native_payment_changed';
        }
        return null;
    }

    private function draft(array $native): array
    {
        $order = $native['order'];
        $scale = $native['decimals'];
        $amount = $order['total'];
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $amount, $parts)
            || !is_int($scale) || $scale < 0 || $scale > 8
            || (strlen($parts[2] ?? '') > $scale && trim(substr($parts[2], $scale), '0') !== '')) {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        $minor = ltrim($parts[1] . substr(str_pad($parts[2] ?? '', $scale, '0'), 0, $scale), '0');
        if ($minor === '') {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        return array('amount_minor' => $minor, 'amount' => $amount, 'currency' => $native['currency'], 'decimals' => $scale,
            'order_id' => (int) $order['order_id'], 'payment_id' => (int) $order['payment_id'],
            'company_id' => (int) ($order['company_id'] ?? 0), 'storefront_id' => (int) ($order['storefront_id'] ?? 0),
            'restock' => false, 'notify' => false);
    }

    private function result(RefundIntent $intent, ?VerifiedRefund $refund): NativeProjectionResult
    {
        if ($refund === null) {
            throw new \RuntimeException('refund_verified_observation_missing');
        }
        $draft = $intent->fullDraft();
        return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(), 'paymos:' . $intent->operationId(),
            $draft !== null ? $draft['amount_minor'] : null, $draft !== null ? $draft['currency'] : null,
            $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
    }

    private function native(int $orderId): array { return call_user_func($this->nativeLoader, $orderId); }

    private function identity(array $native): array
    {
        $order = $native['order'];
        return array('order_id' => (int) $order['order_id'], 'payment_id' => (int) $order['payment_id'],
            'company_id' => (int) ($order['company_id'] ?? 0), 'storefront_id' => (int) ($order['storefront_id'] ?? 0));
    }

    private function readInvoice(string $invoiceId, string $environment, array $native): array
    {
        $client = call_user_func($this->clientFactory, $environment, $native['params']);
        $invoice = $client->invoices()->get($invoiceId);
        $snapshot = $native['snapshot'];
        if (!is_array($snapshot) || ($invoice['invoice_id'] ?? '') !== $invoiceId
            || ($invoice['project_id'] ?? '') !== $snapshot['project_id']
            || ($invoice['is_test'] ?? null) !== ($environment === 'sandbox')
            || ($invoice['order']['external_id'] ?? '') !== $snapshot['external_order_id']
            || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $snapshot['amount'])
            || ($invoice['order']['currency'] ?? '') !== $snapshot['currency']) {
            throw new \RuntimeException('refund_invoice_read_mismatch');
        }
        return $invoice;
    }

    private function reconcileUnbound(array $signal, string $environment): void
    {
        foreach (array('withdrawal_id', 'invoice_id', 'mode', 'external_order_id') as $field) {
            if (!isset($signal[$field]) || !is_string($signal[$field]) || $signal[$field] === '') {
                throw new \RuntimeException('refund_callback_scope_mismatch');
            }
        }
        $mapping = $this->store->invoiceMapping($signal['invoice_id']);
        if ($mapping === null || (isset($signal['project_id']) && $mapping['project_id'] !== $signal['project_id']) || $mapping['environment'] !== $environment
            || ($signal['is_test'] ?? null) !== ($environment === 'sandbox')) {
            throw new \RuntimeException('refund_callback_scope_mismatch');
        }
        // Project comes from the persisted mapping and the fresh invoice GET, not event money.
        $signal['project_id'] = $mapping['project_id'];
        $orderId = (int) $mapping['cscart_order_id'];
        $this->store->withPaymentLock('cscart:' . $orderId, function () use ($signal, $environment, $orderId) {
            $this->store->assertNativeConnection();
            $native = $this->native($orderId);
            $connection = $native['connection'][$environment] ?? array();
            if (($connection['project_id'] ?? '') !== $signal['project_id']
                || ($native['processor']['processor_script'] ?? '') !== 'paymos.php' || ($native['processor']['addon'] ?? '') !== 'paymos') {
                throw new \RuntimeException('refund_payment_mapping_mismatch');
            }
            $client = call_user_func($this->clientFactory, $environment, $native['params']);
            $wire = $client->refunds()->get($signal['withdrawal_id']);
            $invoice = $client->invoices()->get($signal['invoice_id']);
            $refund = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable('now'));
            if ($refund->withdrawalId() !== $signal['withdrawal_id'] || $refund->invoiceId() !== $signal['invoice_id']
                || $refund->projectId() !== $signal['project_id'] || $refund->environment() !== $environment
                || $refund->mode() !== $signal['mode'] || $refund->externalOrderId() !== $signal['external_order_id']
                || $this->store->findByWithdrawalId($refund->withdrawalId()) !== null) {
                throw new \RuntimeException('refund_callback_scope_mismatch');
            }
            $this->store->observeUnbound($orderId, $refund);
        });
    }

    private function orderId(RefundIntent $intent): int
    {
        if (!preg_match('/^cscart:([1-9][0-9]*)$/D', $intent->paymentKey(), $match)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        return (int) $match[1];
    }

    private function assertCompany(array $native): void
    {
        if ((int) $native['company_id'] !== 0 && (int) $native['company_id'] !== (int) ($native['order']['company_id'] ?? 0)) {
            throw new \RuntimeException('refund_company_scope_mismatch');
        }
    }
}
