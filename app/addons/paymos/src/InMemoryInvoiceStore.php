<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class InMemoryInvoiceStore implements InvoiceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private $rows = array();

    private $orderLocks = array();

    public function synchronized($orderId, callable $action)
    {
        $key = (string) (int) $orderId;
        if (isset($this->orderLocks[$key])) {
            throw new \Paymos\Exception\EventInProgressException('Paymos order is busy.');
        }
        $this->orderLocks[$key] = true;
        try { return $action(); }
        finally { unset($this->orderLocks[$key]); }
    }

    public function assertLocked($orderId)
    {
        if (empty($this->orderLocks[(string) (int) $orderId])) {
            throw new \RuntimeException('Paymos order lock is not held.');
        }
    }

    public function findByCsCartOrderId($orderId)
    {
        $matches = array_values(array_filter($this->rows, static function (array $row) use ($orderId) {
            return (int) $row['cscart_order_id'] === (int) $orderId;
        }));

        return count($matches) > 0 ? $matches[count($matches) - 1] : null;
    }

    public function findByExternalOrderId($externalOrderId)
    {
        foreach ($this->rows as $row) {
            if ((string) $row['external_order_id'] === (string) $externalOrderId) {
                return $row;
            }
        }

        return null;
    }

    public function save(array $row)
    {
        foreach ($this->rows as $index => $existing) {
            if ((string) $existing['external_order_id'] === (string) $row['external_order_id']) {
                if (\Paymos\Plugin\StatusMapper::isFinalStatus((string) $existing['status'])) {
                    $row['status'] = $existing['status'];
                }
                if (!array_key_exists('paid_late_noted', $row) && array_key_exists('paid_late_noted', $existing)) {
                    $row['paid_late_noted'] = $existing['paid_late_noted'];
                }
                $this->rows[$index] = $row;
                return;
            }
        }

        $this->rows[] = $row;
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        foreach ($this->rows as $index => $row) {
            if ((string) $row['paymos_invoice_id'] === (string) $paymosInvoiceId) {
                if (\Paymos\Plugin\StatusMapper::isFinalStatus((string) $row['status'])) {
                    return;
                }
                $this->rows[$index]['status'] = (string) $status;
                return;
            }
        }
    }
}
