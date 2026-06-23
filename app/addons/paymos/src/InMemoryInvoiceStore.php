<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class InMemoryInvoiceStore implements InvoiceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private $rows = array();

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
                $this->rows[$index]['status'] = (string) $status;
                return;
            }
        }
    }
}
