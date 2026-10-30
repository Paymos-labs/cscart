<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundIntentStore implements RefundIntentStoreInterface
{
    private $db;
    private $clock;
    private $paymentKey;
    private $lockName;
    private $inTransaction = false;

    public function __construct(?RefundDatabase $db = null, ?callable $clock = null)
    {
        $this->db = $db ?: new RefundDatabase();
        $this->clock = $clock ?: static function () { return new \DateTimeImmutable('now'); };
    }

    public function installationId(): string
    {
        $this->db->query("INSERT IGNORE INTO `" . $this->table('installation') . "` (`id`, `installation_id`) VALUES (1, " . $this->quote(self::uuid()) . ")");
        $row = $this->row("SELECT * FROM `" . $this->table('installation') . "` WHERE `id` = 1");
        if ($row === null) {
            throw new \RuntimeException('refund_installation_unavailable');
        }
        return $row['installation_id'];
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if ($this->paymentKey !== null) {
            $this->assertLock($paymentKey);
            return $action();
        }
        $name = 'pmrf:cs:' . substr(hash('sha256', $this->installationId() . ':' . $paymentKey), 0, 48);
        $row = $this->row('SELECT GET_LOCK(' . $this->quote($name) . ', 0) AS acquired');
        if ($row === null || (string) $row['acquired'] !== '1') {
            throw new \RuntimeException('refund_payment_busy');
        }
        $this->paymentKey = $paymentKey;
        $this->lockName = $name;
        try {
            $this->assertLock($paymentKey);
            $result = $action();
            $this->assertLock($paymentKey);
            return $result;
        } finally {
            try {
                $this->db->query('SELECT RELEASE_LOCK(' . $this->quote($name) . ')');
            } finally {
                $this->paymentKey = null;
                $this->lockName = null;
            }
        }
    }

    private function assertLock(string $paymentKey): void
    {
        if ($this->paymentKey !== $paymentKey || $this->lockName === null) {
            throw new \RuntimeException('refund_payment_lock_required');
        }
        $row = $this->row('SELECT IS_USED_LOCK(' . $this->quote($this->lockName) . ') = CONNECTION_ID() AS owned');
        if ($row === null || (string) $row['owned'] !== '1') {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertLock((string) $this->paymentKey);
        if ($this->inTransaction) {
            return $action();
        }
        $this->db->query('START TRANSACTION');
        $this->inTransaction = true;
        try {
            $result = $action();
            $this->assertLock((string) $this->paymentKey);
            $this->db->query('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->db->query('ROLLBACK');
            throw $error;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        if ($this->inTransaction) {
            throw new \RuntimeException('refund_intent_requires_commit');
        }
        $this->assertLock($intent->paymentKey());
        return $this->transaction(function () use ($intent) {
            $data = $intent->toArray();
            $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($existing !== null) {
                $previous = $existing->toArray();
                foreach (array('platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft', 'native_identity') as $field) {
                    if ($previous[$field] !== $data[$field]) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $existing;
            }
            if ($data['platform'] !== 'cscart' || $data['installation_id'] !== $this->installationId()
                || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $data['request_token'])
                || $intent->withdrawalId() !== null || $intent->cashState() !== RefundStates::NOT_SUBMITTED
                || $intent->projectionState() !== RefundStates::PROJECTION_NONE || $intent->observation() !== null
                || $data['projection'] !== null || $data['effects'] !== array()) {
                throw new \RuntimeException('refund_invalid_new_intent');
            }
            $this->assertNewActionAllowed($intent->paymentKey());
            $data['created_at'] = $this->now()->getTimestamp();
            $this->db->query("INSERT INTO `" . $this->table('intent') . "` SET `operation_id` = " . $this->quote($intent->operationId())
                . ', `installation_id` = ' . $this->quote($data['installation_id']) . ', `request_token` = ' . $this->quote($data['request_token'])
                . ', `payment_key` = ' . $this->quote($intent->paymentKey()) . ', `external_order_id` = ' . $this->quote($intent->externalOrderId())
                . ', `payload` = ' . $this->quote($this->encode($data)) . ', `created_at` = ' . $data['created_at']
                . ', `next_due` = ' . $data['created_at']);
            return $this->getIntent($intent->operationId());
        });
    }

    public function assertNewActionAllowed(string $paymentKey): void
    {
        $this->assertLock($paymentKey);
        foreach ($this->forPayment($paymentKey) as $previous) {
            if ($previous->projectionState() === RefundStates::NATIVE_REVIEW
                || !in_array($previous->cashState(), array(RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED), true)
                || ($previous->cashState() === RefundStates::COMPLETED && $previous->projectionState() !== RefundStates::PROJECTION_APPLIED)
                || ($previous->mode() === RefundStates::FULL && $previous->toArray()['projection'] !== null)) {
                throw new \RuntimeException('refund_payment_unresolved_or_refunded');
            }
        }
        foreach ($this->unboundForPayment($paymentKey) as $row) {
            $refund = VerifiedRefund::fromStorage($this->decode($row['payload']));
            if ($row['review_reason'] !== null || !in_array($refund->status(), array('completed', 'failed', 'cancelled'), true)
                || ($refund->status() === 'completed' && ($refund->mode() === RefundStates::FULL || $row['result'] === null))) {
                throw new \RuntimeException('refund_unbound_requires_review');
            }
        }
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        return $this->find('`installation_id` = ' . $this->quote($installationId) . ' AND `request_token` = ' . $this->quote($token));
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $intent = $this->find('`operation_id` = ' . $this->quote($operationId));
        if ($intent === null) {
            throw new \RuntimeException('refund_intent_missing');
        }
        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent
    {
        return $this->find('`external_order_id` = ' . $this->quote($key));
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        return $this->find('`withdrawal_id` = ' . $this->quote($id));
    }

    private function find(string $where): ?RefundIntent
    {
        $row = $this->row('SELECT * FROM `' . $this->table('intent') . '` WHERE ' . $where . ' LIMIT 1');
        return $row === null ? null : new RefundIntent($this->decode($row['payload']));
    }

    public function forPayment(string $key): array
    {
        $query = $this->db->query('SELECT * FROM `' . $this->table('intent') . '` WHERE `payment_key` = ' . $this->quote($key) . ' ORDER BY `created_at`, `operation_id`');
        return array_map(function ($row) { return new RefundIntent($this->decode($row['payload'])); }, $query->rows);
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id) {
            $intent = $this->getIntent($operationId);
            $this->assertLock($intent->paymentKey());
            $other = $this->findByWithdrawalId($id);
            if ($id === '' || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ($intent->withdrawalId() === $id) {
                return 'same';
            }
            try {
                $this->db->query('UPDATE `' . $this->table('intent') . '` SET `withdrawal_id` = ' . $this->quote($id)
                    . ' WHERE `operation_id` = ' . $this->quote($operationId) . ' AND `withdrawal_id` IS NULL');
            } catch (\Exception $error) {
                $other = $this->findByWithdrawalId($id);
                if ($other === null || $other->operationId() === $operationId) {
                    throw $error;
                }
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ((int) $this->db->countAffected() !== 1) {
                throw new \RuntimeException('refund_binding_conflict');
            }
            $this->update($intent, array('withdrawal_id' => $id));
            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot) {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if ($snapshot->withdrawalId() !== $intent->withdrawalId() || $snapshot->invoiceId() !== $intent->invoiceId()
                || $snapshot->projectId() !== $intent->projectId() || $snapshot->environment() !== $intent->environment()
                || $snapshot->mode() !== $intent->mode() || $snapshot->externalOrderId() !== $intent->externalOrderId()
                || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network() || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }
            $this->update($intent, array('observation' => $snapshot->toArray()));
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result) {
            $intent = $this->getIntent($operationId);
            $saved = $intent->toArray()['projection'];
            $pin = $intent->observation();
            $draft = $intent->fullDraft();
            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $result->mode() !== $intent->mode()
                || $result->nativeId() === null || $result->nativeId() === '' || $pin === null || $pin->status() !== 'completed'
                || ($draft !== null && ($result->nativeAmountMinor() !== $draft['amount_minor'] || $result->nativeCurrency() !== $draft['currency']))
                || ($draft === null && ($result->nativeAmountMinor() !== null || $result->nativeCurrency() !== null
                    || !AmountGuard::amountsEqual($result->cryptoObservationAmount(), $pin->principalDecimal())
                    || $result->cryptoAsset() !== $pin->currency() . '/' . $pin->network()))
                || ($saved !== null && $saved !== $result->toArray())) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $this->update($intent, array('projection' => $result->toArray()));
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason) {
            if (!in_array($cashState, array('not_submitted', 'unknown', 'created', 'processing', 'completed', 'failed', 'cancelled', 'review'), true)
                || !in_array($projectionState, array('none', 'pending', 'applied', 'native_review'), true)) {
                throw new \InvalidArgumentException('refund_invalid_state');
            }
            $this->update($this->getIntent($operationId), array('cash_state' => $cashState,
                'projection_state' => $projectionState, 'review_reason' => $reason));
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($this->inTransaction || $kind === '' || $generation < 1) {
            throw new \RuntimeException('refund_effect_requires_commit');
        }
        return $this->transaction(function () use ($operationId, $kind, $generation) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            if ($intent->cashState() !== RefundStates::COMPLETED || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED) {
                throw new \RuntimeException('refund_effect_not_ready');
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->update($intent, array('effects' => $effects));
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        $this->transaction(function () use ($operationId, $kind, $generation, $state) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (!isset($effects[$key]) || $effects[$key] !== RefundStates::EFFECT_STARTED
                || !in_array($state, array('delivered', 'failed_no_effect', 'unknown', 'skipped'), true)) {
                throw new \RuntimeException('refund_effect_conflict');
            }
            $effects[$key] = $state;
            $this->update($intent, array('effects' => $effects));
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $limit = max(1, min(100, $limit));
        $after = '';
        if ($cursor !== null) {
            $parts = json_decode((string) base64_decode($cursor, true), true);
            if (!is_array($parts) || count($parts) !== 2 || !is_int($parts[0]) || !is_string($parts[1])) {
                throw new \InvalidArgumentException('refund_invalid_cursor');
            }
            $after = ' AND (`next_due` > ' . $parts[0] . ' OR (`next_due` = ' . $parts[0] . ' AND `operation_id` > ' . $this->quote($parts[1]) . '))';
        }
        $query = $this->db->query('SELECT * FROM `' . $this->table('intent') . '` WHERE `next_due` <= ' . $now->getTimestamp()
            . $after . ' ORDER BY `next_due`, `operation_id` LIMIT ' . ($limit + 1));
        $rows = $query->rows;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = count($rows) ? $rows[count($rows) - 1] : null;
        return new RefundIntentPage(array_map(function ($row) { return new RefundIntent($this->decode($row['payload'])); }, $rows),
            $more && $last !== null ? base64_encode($this->encode(array((int) $last['next_due'], $last['operation_id']))) : null);
    }

    public function schedule(string $operationId): void
    {
        $this->transaction(function () use ($operationId) {
            $intent = $this->getIntent($operationId);
            $row = $this->row('SELECT * FROM `' . $this->table('intent') . '` WHERE `operation_id` = ' . $this->quote($operationId));
            $now = $this->now()->getTimestamp();
            if ($now - (int) $row['created_at'] > 86400 && in_array($intent->cashState(), array('not_submitted', 'unknown', 'created', 'processing'), true)) {
                $this->recordState($operationId, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_unresolved_24h');
                $intent = $this->getIntent($operationId);
            }
            $delays = array(60, 120, 300, 900, 3600);
            $delay = in_array($intent->cashState(), array('completed', 'failed', 'cancelled', 'review'), true) ? 86400
                : $delays[min((int) $row['poll_count'], count($delays) - 1)];
            if ($intent->cashState() === 'completed' && $intent->projectionState() === RefundStates::PROJECTION_PENDING) {
                $delay = 60;
            }
            $this->db->query('UPDATE `' . $this->table('intent') . '` SET `next_due` = ' . ($now + $delay)
                . ', `poll_count` = `poll_count` + 1 WHERE `operation_id` = ' . $this->quote($operationId));
        });
    }

    public function assertNativeConnection(): void
    {
        foreach (array('orders', 'paymos_invoices', 'paymos_refund_intent', 'paymos_refund_projection', 'paymos_refund_installation', 'paymos_refund_unbound') as $name) {
            $row = $this->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->quote($this->db->table($name)));
            if ($row === null || strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) {
                throw new \RuntimeException('refund_requires_innodb');
            }
        }
    }

    public function projectionRow(string $operationId): ?array
    {
        return $this->row('SELECT * FROM `' . $this->table('projection') . '` WHERE `operation_id` = ' . $this->quote($operationId));
    }

    public function saveNativeProjection(RefundIntent $intent, int $orderId, NativeProjectionResult $result): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException('refund_native_transaction_required');
        }
        $this->assertLock($intent->paymentKey());
        $this->db->query('INSERT INTO `' . $this->table('projection') . '` SET `operation_id` = ' . $this->quote($intent->operationId())
            . ', `order_id` = ' . $orderId . ', `invoice_id` = ' . $this->quote($intent->invoiceId())
            . ', `withdrawal_id` = ' . $this->quote((string) $intent->withdrawalId())
            . ', `mode` = ' . $this->quote($intent->mode())
            . ', `note` = ' . $this->quote('Paymos refund completed.')
            . ', `result` = ' . $this->quote($this->encode($result->toArray())));
    }

    public function database(): RefundDatabase { return $this->db; }

    public function invoiceMapping(string $invoiceId): ?array
    {
        $rows = $this->db->query('SELECT * FROM ?:paymos_invoices WHERE paymos_invoice_id = ' . $this->quote($invoiceId) . ' LIMIT 2')->rows;
        return count($rows) === 1 ? $rows[0] : null;
    }

    public function unboundForPayment(string $paymentKey): array
    {
        if (!preg_match('/^cscart:([1-9][0-9]*)$/D', $paymentKey, $match)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        return $this->db->query('SELECT * FROM `' . $this->table('unbound') . '` WHERE `order_id` = ' . (int) $match[1])->rows;
    }

    public function unboundDue(\DateTimeImmutable $now, int $limit): array
    {
        return $this->db->query('SELECT * FROM `' . $this->table('unbound') . '` WHERE `next_due` <= ' . $now->getTimestamp()
            . ' ORDER BY `next_due`, `withdrawal_id` LIMIT ' . max(1, min(100, $limit)))->rows;
    }

    public function deferUnbound(array $row): void
    {
        $this->transaction(function () use ($row) {
            $this->assertLock('cscart:' . (int) $row['order_id']);
            $delays = array(60, 120, 300, 900, 3600);
            $delay = $delays[min((int) $row['poll_count'], count($delays) - 1)];
            $this->db->query('UPDATE `' . $this->table('unbound') . '` SET `next_due` = ' . ($this->now()->getTimestamp() + $delay)
                . ', `poll_count` = `poll_count` + 1 WHERE `withdrawal_id` = ' . $this->quote($row['withdrawal_id']));
        });
    }

    public function observeUnbound(int $orderId, VerifiedRefund $refund): void
    {
        $this->transaction(function () use ($orderId, $refund) {
            $this->assertLock('cscart:' . $orderId);
            $row = $this->row('SELECT * FROM `' . $this->table('unbound') . '` WHERE `withdrawal_id` = ' . $this->quote($refund->withdrawalId()));
            $result = null;
            if ($row !== null) {
                $pin = VerifiedRefund::fromStorage($this->decode($row['payload']));
                if ((int) $row['order_id'] !== $orderId || $pin->invoiceId() !== $refund->invoiceId()
                    || $pin->projectId() !== $refund->projectId() || $pin->environment() !== $refund->environment()
                    || $pin->mode() !== $refund->mode() || $pin->externalOrderId() !== $refund->externalOrderId()
                    || !AmountGuard::amountsEqual($pin->principalDecimal(), $refund->principalDecimal())
                    || $pin->currency() !== $refund->currency() || $pin->network() !== $refund->network() || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $refund)) {
                    throw new \RuntimeException('refund_unbound_evidence_conflict');
                }
                $result = $row['result'];
            }
            if ($refund->mode() === RefundStates::OVERPAYMENT && $refund->status() === 'completed' && $result === null) {
                $result = $this->encode((new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, RefundStates::OVERPAYMENT,
                    'paymos-unbound:' . $refund->withdrawalId(), null, null, $refund->principalDecimal(),
                    $refund->currency() . '/' . $refund->network()))->toArray());
            }
            $reason = $refund->mode() === RefundStates::FULL ? 'refund_unbound_full_without_draft'
                : ($result !== null && $refund->status() !== 'completed' ? 'cash_rewound' : null);
            $now = $this->now()->getTimestamp();
            $created = $row === null ? $now : (int) $row['created_at'];
            $pending = !in_array($refund->status(), array('completed', 'failed', 'cancelled'), true);
            if ($pending && $now - $created > 86400) {
                $reason = $reason ?: 'refund_unresolved_24h';
            }
            $delays = array(60, 120, 300, 900, 3600);
            $delay = $pending ? $delays[min($row === null ? 0 : (int) $row['poll_count'], count($delays) - 1)] : 86400;
            $set = '`payload` = ' . $this->quote($this->encode($refund->toArray()))
                . ', `result` = ' . ($result === null ? 'NULL' : $this->quote($result))
                . ', `review_reason` = ' . ($reason === null ? 'NULL' : $this->quote($reason))
                . ', `next_due` = ' . ($now + $delay)
                . ', `poll_count` = ' . ($row === null ? 1 : (int) $row['poll_count'] + 1);
            if ($row === null) {
                $this->db->query('INSERT INTO `' . $this->table('unbound') . '` SET `withdrawal_id` = ' . $this->quote($refund->withdrawalId())
                    . ', `order_id` = ' . $orderId . ', `created_at` = ' . $created . ', `note` = ' . $this->quote('Paymos refund made outside CS-Cart.') . ', ' . $set);
            } else {
                $this->db->query('UPDATE `' . $this->table('unbound') . '` SET ' . $set . ' WHERE `withdrawal_id` = ' . $this->quote($refund->withdrawalId()));
            }
        });
    }

    private function update(RefundIntent $intent, array $changes): void
    {
        $this->assertLock($intent->paymentKey());
        $data = array_replace($intent->toArray(), $changes);
        $this->db->query('UPDATE `' . $this->table('intent') . '` SET `payload` = ' . $this->quote($this->encode($data))
            . ' WHERE `operation_id` = ' . $this->quote($intent->operationId()));
    }

    private function table(string $suffix): string { return '?:paymos_refund_' . $suffix; }
    private function quote(string $value): string { return "'" . $this->db->escape($value) . "'"; }
    private function now(): \DateTimeImmutable { return call_user_func($this->clock); }
    private function row(string $sql): ?array
    {
        $query = $this->db->query($sql);
        return isset($query->row) && is_array($query->row) && count($query->row) ? $query->row : null;
    }
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
