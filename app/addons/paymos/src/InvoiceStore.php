<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class InvoiceStore implements InvoiceStoreInterface
{
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

        $existing = $this->findByExternalOrderId($data['external_order_id']);
        if (is_array($existing)) {
            db_query('UPDATE ?:paymos_invoices SET ?u WHERE external_order_id = ?s', $data, $data['external_order_id']);
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
            'UPDATE ?:paymos_invoices SET status = ?s, updated_at = ?s WHERE paymos_invoice_id = ?s',
            (string) $status,
            date('Y-m-d H:i:s'),
            (string) $paymosInvoiceId
        );
    }
}
