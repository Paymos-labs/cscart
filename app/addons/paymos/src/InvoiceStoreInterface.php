<?php

declare(strict_types=1);

namespace PaymosCsCart;

interface InvoiceStoreInterface
{
    /** Serialize checkout and webhook processing for a native order. */
    public function synchronized($orderId, callable $action);

    public function assertLocked($orderId);

    public function findByCsCartOrderId($orderId);

    public function findByExternalOrderId($externalOrderId);

    public function save(array $row);

    public function updateStatus($paymosInvoiceId, $status);
}
