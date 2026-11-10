<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class InvoiceStore implements InvoiceStoreInterface
{
    private $orderLocks = array();

    public function synchronized($orderId, callable $action)
    {
        Migrations::ensure();
        if (!function_exists('db_get_field')) {
            throw new \RuntimeException('CS-Cart database is unavailable.');
        }
        $key = (string) (int) $orderId;
        if (isset($this->orderLocks[$key])) {
            throw new \LogicException('Paymos order lock is already held by this store.');
        }
        $name = hash('sha256', db_get_field('SELECT DATABASE()') . ':paymos_cscart:' . $key);
        $acquired = db_get_field('SELECT GET_LOCK(?s, 0)', $name);
        if ((string) $acquired === '0') {
            throw new \Paymos\Exception\EventInProgressException('Paymos order is busy.');
        }
        if ((string) $acquired !== '1') {
            throw new \RuntimeException('Paymos order lock could not be acquired.');
        }
        $this->orderLocks[$key] = $name;
        try {
            $this->assertLocked($key);
            return $action();
        } finally {
            unset($this->orderLocks[$key]);
            db_get_field('SELECT RELEASE_LOCK(?s)', $name);
        }
    }

    public function assertLocked($orderId)
    {
        $name = $this->orderLocks[(string) (int) $orderId] ?? '';
        if ($name === '' || (string) db_get_field('SELECT IS_USED_LOCK(?s) = CONNECTION_ID()', $name) !== '1') {
            throw new \RuntimeException('Paymos order lock ownership was lost.');
        }
    }

    public function findByCsCartOrderId($orderId)
    {
        Migrations::ensure();
        if (!function_exists('db_get_row')) {
            return null;
        }

        $row = db_get_row(
            'SELECT * FROM ?:paymos_invoices WHERE cscart_order_id = ?i ORDER BY id DESC LIMIT 1',
            (int) $orderId
        );

        return is_array($row) && count($row) > 0 ? $row : null;
    }

    public function findByExternalOrderId($externalOrderId)
    {
        Migrations::ensure();
        if (!function_exists('db_get_row')) {
            return null;
        }

        $row = db_get_row(
            'SELECT * FROM ?:paymos_invoices WHERE external_order_id = ?s LIMIT 1',
            (string) $externalOrderId
        );

        return is_array($row) && count($row) > 0 ? $row : null;
    }

    public function save(array $row)
    {
        Migrations::ensure();
        if (!function_exists('db_query')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $data = array(
            'cscart_order_id' => (int) $row['cscart_order_id'],
            'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
            'external_order_id' => (string) $row['external_order_id'],
            'environment' => (string) $row['environment'],
            'project_id' => (string) $row['project_id'],
            'amount' => (string) $row['amount'],
            'currency' => strtoupper((string) $row['currency']),
            'payment_url' => (string) $row['payment_url'],
            'status' => (string) $row['status'],
            'renew_count' => isset($row['renew_count']) ? (int) $row['renew_count'] : 0,
            'updated_at' => $now,
        );
        // Written only by the callback that set it; other saves leave the marker alone.
        if (array_key_exists('paid_late_noted', $row)) {
            $data['paid_late_noted'] = $row['paid_late_noted'] === null ? null : (string) $row['paid_late_noted'];
        }

        $existing = $this->findByExternalOrderId($data['external_order_id']);
        if (is_array($existing)) {
            $status = $data['status'];
            unset($data['status']);
            db_query('UPDATE ?:paymos_invoices SET ?u WHERE external_order_id = ?s', $data, $data['external_order_id']);
            $this->updateStatus($data['paymos_invoice_id'], $status);
            return;
        }

        $data['created_at'] = $now;
        db_query('INSERT INTO ?:paymos_invoices ?e', $data);
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        Migrations::ensure();
        if (!function_exists('db_query')) {
            return;
        }

        db_query(
            "UPDATE ?:paymos_invoices SET status = ?s, updated_at = ?s WHERE paymos_invoice_id = ?s "
            . "AND status NOT IN ('paid','paid_over','underpaid','expired','cancelled')",
            (string) $status,
            date('Y-m-d H:i:s'),
            (string) $paymosInvoiceId
        );
    }
}
