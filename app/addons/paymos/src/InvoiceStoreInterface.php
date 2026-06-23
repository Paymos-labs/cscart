<?php

declare(strict_types=1);

namespace PaymosCsCart;

interface InvoiceStoreInterface
{
    public function findByCsCartOrderId($orderId);

    public function findByExternalOrderId($externalOrderId);

    public function save(array $row);

    public function updateStatus($paymosInvoiceId, $status);
}
